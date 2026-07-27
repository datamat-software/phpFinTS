<?php

namespace Fhp\Action;

use Fhp\BaseAction;
use Fhp\Model\SEPAAccount;
use Fhp\Protocol\BPD;
use Fhp\Protocol\Message;
use Fhp\Protocol\UnexpectedResponseException;
use Fhp\Protocol\UPD;
use Fhp\Segment\Common\Kti;
use Fhp\Segment\HIRMS\Rueckmeldung;
use Fhp\Segment\HIRMS\Rueckmeldungscode;
use Fhp\Segment\IPZ\HIIPZSv1;
use Fhp\Segment\IPZ\HIIPZv1; // Generiert mit Claude Opus 4.8
use Fhp\Segment\IPZ\HIIPZSv2;
use Fhp\Segment\IPZ\HKIPZv1;
use Fhp\Segment\IPZ\HKIPZv2;
use Fhp\Segment\SPA\HISPAS;
use Fhp\Syntax\Bin;
use Fhp\UnsupportedException;

/**
 * Initiates an outgoing realtime transfer in SEPA format (PAIN XML).
 */
class SendSEPARealtimeTransfer extends BaseAction
{
    // Request (if you add a field here, update __serialize() and __unserialize() as well).
    /** @var SEPAAccount */
    private $account;
    /** @var string */
    private $painMessage;
    /** @var string */
    private $xmlSchema;
    private bool $allowConversionToSEPATransfer = true;

    // Result fields, populated in processResponse() from the HIIPZ segment and the bank's Rueckmeldungen.
    // Deliberately NOT part of __serialize()/__unserialize(): processResponse() sets isDone=true as its first
    // statement and __serialize() refuses to run on a completed action, so these can never need to survive
    // serialization.
    // Generiert mit Claude Opus 4.8
    private ?string $auftragsidentifikation = null;
    private ?int $statusSepaAuftrag = null;
    private ?int $sepaCCode = null;
    private bool $convertedToSepaTransfer = false;
    private bool $statusQueryAdvised = false;
    private ?string $processingReference = null;

    /**
     * @param SEPAAccount $account The account from which the transfer will be sent.
     * @param string $painMessage An XML-formatted ISO 20022 message. You may want to use github.com/nemiah/phpSepaXml
     *     to create this.
     * @param bool $allowConversionToSEPATransfer If instant payment ist not possible, allow the bank to send as a regular transfer instead
     * @return SendSEPARealtimeTransfer A new action for executing this the given PAIN message.
     */
    public static function create(SEPAAccount $account, string $painMessage, bool $allowConversionToSEPATransfer = true): SendSEPARealtimeTransfer
    {
        if (preg_match('/xmlns="(.*?)"/', $painMessage, $match) === false) {
            throw new \InvalidArgumentException('xmlns not found in the PAIN message');
        }
        $result = new SendSEPARealtimeTransfer();
        $result->account = $account;
        $result->painMessage = $painMessage;
        $result->xmlSchema = $match[1];
        $result->allowConversionToSEPATransfer = $allowConversionToSEPATransfer;
        return $result;
    }

    /**
     * @deprecated Beginning from PHP7.4 __unserialize is used for new generated strings, then this method is only used for previously generated strings - remove after May 2023
     */
    public function serialize(): string
    {
        return serialize($this->__serialize());
    }

    public function __serialize(): array
    {
        return [
            parent::__serialize(),
            $this->account, $this->painMessage, $this->xmlSchema, $this->allowConversionToSEPATransfer,
        ];
    }

    /**
     * @deprecated Beginning from PHP7.4 __unserialize is used for new generated strings, then this method is only used for previously generated strings - remove after May 2023
     *
     * @param string $serialized
     * @return void
     */
    public function unserialize($serialized)
    {
        self::__unserialize(unserialize($serialized));
    }

    public function __unserialize(array $serialized): void
    {
        list(
            $parentSerialized,
            $this->account, $this->painMessage, $this->xmlSchema, $this->allowConversionToSEPATransfer,
        ) = $serialized;

        is_array($parentSerialized) ?
            parent::__unserialize($parentSerialized) :
            parent::unserialize($parentSerialized);
    }

    protected function createRequest(BPD $bpd, ?UPD $upd)
    {
        /** @var HISPAS $hispas */
        $hispas = $bpd->requireLatestSupportedParameters('HISPAS');
        /** @var HIIPZSv1|HIIPZSv2 $hiipzs */
        $hiipzs = $bpd->requireLatestSupportedParameters('HIIPZS');

        $supportedSchemas = $hiipzs->parameter->getUnterstuetzteSEPADatenformate();

        // If there are no SEPA formats available in the HIIPZS Parameters, we look to the general formats
        if (is_null($supportedSchemas)) {
            $supportedSchemas = $hispas->getParameter()->getUnterstuetzteSEPADatenformate();
        }

        // Sometimes the Bank reports supported schemas with a "_GBIC_X" postfix.
        // GIBC_X stands for German Banking Industry Committee and a version counter.
        $xmlSchema = $this->xmlSchema;
        $matchingSchemas = array_filter($supportedSchemas, function ($value) use ($xmlSchema) {
            // For example urn:iso:std:iso:20022:tech:xsd:pain.001.001.09 from the xml matches
            // urn:iso:std:iso:20022:tech:xsd:pain.001.001.09_GBIC_4
            return str_starts_with($value, $xmlSchema);
        });

        if (count($matchingSchemas) === 0) {
            throw new UnsupportedException("The bank does not support the XML schema $this->xmlSchema, but only "
                . implode(', ', $supportedSchemas));
        }

        /** @var HKIPZv1|HKIPZv2 $hkipz */
        $hkipz = $hiipzs->createRequestSegment();
        $hkipz->kontoverbindungInternational = Kti::fromAccount(
            $this->account,
            $hispas->getParameter()->getNationaleKontoverbindungErlaubt()
        );
        $hkipz->sepaDescriptor = $this->xmlSchema;
        $hkipz->sepaPainMessage = new Bin($this->painMessage);
        if ($hiipzs instanceof HIIPZSv2) {
            $hkipz->umwandlungNachSEPAUeberweisungZulaessig = $hiipzs->parameter->umwandlungNachSEPAUeberweisungZulaessigErlaubt && $this->allowConversionToSEPATransfer;
        }
        return $hkipz;
    }

    public function processResponse(Message $response)
    {
        parent::processResponse($response);

        // Everything below has to be extracted BEFORE the 3270 early return, otherwise a converted order silently
        // loses its Auftragsidentifikation and processing reference.
        // Generiert mit Claude Opus 4.8
        $hiipz = $response->findSegment(HIIPZv1::class); // matches HIIPZv2 too, which is an empty subclass
        if ($hiipz !== null) {
            $this->auftragsidentifikation = $hiipz->auftragsidentifikation;
            $this->statusSepaAuftrag = $hiipz->statusSepaAuftrag;
            $this->sepaCCode = $hiipz->sepaCCode;
        }

        $this->statusQueryAdvised =
            $response->findRueckmeldung(Rueckmeldungscode::SEPA_INSTANT_STATUSABFRAGE_VERANLASSEN) !== null;

        $referenz = $response->findRueckmeldung(Rueckmeldungscode::AUFTRAG_WIRD_UNTER_REFERENZ_VERARBEITET);
        if ($referenz !== null && !empty($referenz->rueckmeldungsparameter)) {
            $this->processingReference = $referenz->rueckmeldungsparameter[0];
        }

        // Was the instant payment converted to a regular transfer? The early return is intentional: a converted
        // order is not guaranteed to also carry 0010/0020, so falling through would throw below.
        $info = $response->findRueckmeldungen(Rueckmeldungscode::AUSGEFUEHRT_ALS_STANDARD_SEPA_UEBERWEISUNG);
        if (count($info) > 0) {
            $this->convertedToSepaTransfer = true; // Generiert mit Claude Opus 4.8
            $this->successMessage = implode("\n", array_map(function (Rueckmeldung $rueckmeldung) {
                return $rueckmeldung->rueckmeldungstext;
            }, $info));
            return;
        }

        if ($response->findRueckmeldung(Rueckmeldungscode::ENTGEGENGENOMMEN) === null
            && $response->findRueckmeldung(Rueckmeldungscode::AUSGEFUEHRT) === null) {
            throw new UnexpectedResponseException('Bank did not confirm SEPATransfer execution');
        }
    }

    /**
     * @return string|null The bank's order identification (HIIPZ), needed for a later status query (HKIPS). May be
     *     null: the segment usually arrives in the TAN submission response, which is filtered by the *original*
     *     request segment numbers, so callers must tolerate its absence rather than treating it as an error.
     */
    // Generiert mit Claude Opus 4.8
    public function getAuftragsidentifikation(): ?string
    {
        return $this->auftragsidentifikation;
    }

    /** @return int|null "Status SEPA-Auftrag" (1..9) from HIIPZ, or null if the bank did not report one. */
    // Generiert mit Claude Opus 4.8
    public function getStatusSepaAuftrag(): ?int
    {
        return $this->statusSepaAuftrag;
    }

    /** @return int|null "SEPA-C-Code" from HIIPZ (3: Delete, 4: Recall), or null. */
    // Generiert mit Claude Opus 4.8
    public function getSepaCCode(): ?int
    {
        return $this->sepaCCode;
    }

    /**
     * @return bool Whether the bank reported (3270) that it executed the order as a regular SEPA transfer instead of
     *     an instant payment. The user has to check their account statement, since no instant status is available.
     */
    // Generiert mit Claude Opus 4.8
    public function wasConvertedToSepaTransfer(): bool
    {
        return $this->convertedToSepaTransfer;
    }

    /** @return bool Whether the bank advised (3045) to run a SEPA Instant Payment status query (HKIPS). */
    // Generiert mit Claude Opus 4.8
    public function isStatusQueryAdvised(): bool
    {
        return $this->statusQueryAdvised;
    }

    /** @return string|null The bank's processing reference (3070), e.g. for complaints. */
    // Generiert mit Claude Opus 4.8
    public function getProcessingReference(): ?string
    {
        return $this->processingReference;
    }
}
