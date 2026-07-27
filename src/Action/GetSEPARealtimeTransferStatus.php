<?php

namespace Fhp\Action;

use Fhp\BaseAction;
use Fhp\Model\SEPAAccount;
use Fhp\Protocol\BPD;
use Fhp\Protocol\Message;
use Fhp\Protocol\UPD;
use Fhp\Segment\Common\Kti;
use Fhp\Segment\HIRMS\Rueckmeldungscode;
use Fhp\Segment\IPS\HIIPSSv1;
use Fhp\Segment\IPS\HIIPSv1;
use Fhp\Segment\IPS\HKIPSv1;
use Fhp\Segment\IPS\UnterstuetzteSEPAPainMessages;
use Fhp\Segment\SPA\HISPAS;
use Fhp\UnsupportedException;

/**
 * Queries the final settlement status of a SEPA instant payment that was submitted earlier with
 * {@link SendSEPARealtimeTransfer}. The bank asks for this by returning Rueckmeldung 3045 on the payment; the
 * Auftragsidentifikation to pass here comes from {@link SendSEPARealtimeTransfer::getAuftragsidentifikation()}.
 *
 * This is deliberately a separate action rather than a polling state on the transfer action: the transfer itself is
 * already done at that point, and {@link BaseAction::__serialize()} refuses to serialize a completed action, so the
 * transfer could not be carried across a client round trip at all.
 *
 * Note that the bank may answer with 3045 again, meaning the result is still pending and the query should be repeated
 * after a while.
 */
// Generiert mit Claude Opus 4.8
class GetSEPARealtimeTransferStatus extends BaseAction
{
    // Request (if you add a field here, update __serialize() and __unserialize() as well).
    private SEPAAccount $account;
    private string $auftragsidentifikation;

    // Result fields, see the note in SendSEPARealtimeTransfer: never serialized, because processResponse() marks the
    // action as done and __serialize() rejects completed actions.
    private ?int $statusSepaAuftrag = null;
    private ?int $sepaCCode = null;
    private bool $statusStillPending = false;
    private bool $noEntries = false;

    public static function create(SEPAAccount $account, string $auftragsidentifikation): GetSEPARealtimeTransferStatus
    {
        if (trim($auftragsidentifikation) === '') {
            throw new \InvalidArgumentException('auftragsidentifikation must not be empty');
        }
        $result = new GetSEPARealtimeTransferStatus();
        $result->account = $account;
        $result->auftragsidentifikation = $auftragsidentifikation;
        return $result;
    }

    public function __serialize(): array
    {
        return [parent::__serialize(), $this->account, $this->auftragsidentifikation];
    }

    public function __unserialize(array $serialized): void
    {
        list($parentSerialized, $this->account, $this->auftragsidentifikation) = $serialized;
        is_array($parentSerialized) ? parent::__unserialize($parentSerialized) : parent::unserialize($parentSerialized);
    }

    protected function createRequest(BPD $bpd, ?UPD $upd)
    {
        if (!array_key_exists('HIIPSS', $bpd->parameters)) {
            throw new UnsupportedException('The bank does not support SEPA instant payment status queries (HKIPS)');
        }

        // Deliberately not requireLatestSupportedParameters(): the presence check above already established that the
        // bank offers HKIPS. Should it offer only a HIIPSS version this library does not model yet, that must not
        // abort the query - the segment is read for an optional schema list, nothing the request depends on.
        $hiipss = $bpd->getLatestSupportedParameters('HIIPSS');
        /** @var HISPAS $hispas */
        $hispas = $bpd->requireLatestSupportedParameters('HISPAS');

        // Which pain schemas we can accept in the response. HIIPSS carries its own (optional) list for this specific
        // transaction; only when the bank leaves it empty does the account-wide HISPAS list apply.
        $supportedSchemas = $hiipss instanceof HIIPSSv1 ? $hiipss->getParameter()->getUnterstuetzteSEPADatenformate() : [];
        if (count($supportedSchemas) === 0) {
            $supportedSchemas = $hispas->getParameter()->getUnterstuetzteSEPADatenformate() ?? [];
        }
        $painSchemas = array_values(array_filter($supportedSchemas, function ($schema) {
            return str_contains($schema, 'pain.001');
        }));
        if (count($painSchemas) === 0) {
            throw new UnsupportedException('The bank does not report any pain.001 schema in HIIPSS or HISPAS');
        }

        return HKIPSv1::create(
            Kti::fromAccount($this->account, $hispas->getParameter()->getNationaleKontoverbindungErlaubt()),
            UnterstuetzteSEPAPainMessages::create($painSchemas),
            $this->auftragsidentifikation
        );
    }

    public function processResponse(Message $response)
    {
        parent::processResponse($response);

        $hiips = $response->findSegment(HIIPSv1::class);
        if ($hiips !== null) {
            $this->statusSepaAuftrag = $hiips->statusSepaAuftrag;
            $this->sepaCCode = $hiips->sepaCCode;
        }

        // 3045 here means "run the status query again later" - the interbank result is still outstanding.
        $this->statusStillPending =
            $response->findRueckmeldung(Rueckmeldungscode::SEPA_INSTANT_STATUSABFRAGE_VERANLASSEN) !== null;

        // 3010 "Es liegen keine Einträge vor" - the bank has nothing to report for this order.
        //
        // Caveat, observed in the wild (Sparkasse, 2026-07-27): some banks send 3010 in the HKIPS response with the
        // opposite, positive meaning - the accompanying text was "Geld für den Empfänger verfügbar." alongside a
        // regular order status of 7. Callers must therefore treat a final order status as authoritative over this
        // flag, and only fall back to it when no status was reported at all.
        $this->noEntries = $response->findRueckmeldung(Rueckmeldungscode::NICHT_VERFUEGBAR) !== null;
    }

    /** @return int|null "Status SEPA-Auftrag" (1..9), or null if the bank did not report one. */
    public function getStatusSepaAuftrag(): ?int
    {
        return $this->statusSepaAuftrag;
    }

    /** @return int|null "SEPA-C-Code" (1..4), or null. */
    public function getSepaCCode(): ?int
    {
        return $this->sepaCCode;
    }

    /** @return bool Whether the bank asked (3045) for the status query to be repeated later. */
    public function isStatusStillPending(): bool
    {
        return $this->statusStillPending;
    }

    /** @return bool Whether the bank reported (3010) that it has no entry for this order. */
    public function hasNoEntries(): bool
    {
        return $this->noEntries;
    }
}
