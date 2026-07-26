<?php

namespace Fhp\Model;

/**
 * Accumulates the pain.002 payment status report that a bank delivers for a Verification of Payee check across
 * several HKVPP/HIVPP round trips (Aufsetzpunkt mechanism). Two entirely independent mechanisms make this necessary,
 * both described in FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01_2025_06_27_FV.pdf, chapter D:
 *
 *  1. "Art der Lieferung Payment Status Report" (HIVPPS):
 *     - "V" (vollständig): every delivery repeats all data accumulated so far, so the most recent delivery replaces
 *       all previous ones.
 *     - "S" (schrittweise): every delivery only reports the transactions that newly reached a final state; the client
 *       has to assemble the deliveries into one overall result ("zu einer Gesamt-pain.002 zusammengesetzt").
 *  2. Independently of that, a single pain.002 whose data volume becomes too large "muss [...] an geeigneter Stelle
 *     durchgeschnitten und in Teilstücken übertragen werden. Die Teilstücke ergeben durch einfaches Zusammensetzen in
 *     der Reihenfolge der Übertragung wieder die ursprüngliche pain-Nachricht." Such a chunk is not valid XML on its
 *     own, which is how {@link appendChunk()} detects it.
 *
 * An instance of this class lives on the {@link \Fhp\BaseAction} and is therefore serialized along with it, so the
 * accumulation survives asynchronous polling in separate PHP processes.
 */
// Generiert mit Claude Opus 4.8
class VopReportAccumulator
{
    /** Raw bytes of a pain.002 that has been cut into chunks and has not been fully transmitted yet. */
    private string $pendingChunkBuffer = '';

    /** The group-level ("primary") result of the most recent complete delivery, or null if there was none yet. */
    private ?array $groupResultArray = null;

    /** @var VopTransactionResult[] Per-transaction results that carry an EndToEndId, keyed by that EndToEndId. */
    private array $transactionResultByEndToEndIdArray = [];

    /** @var VopTransactionResult[] Per-transaction results the bank did not attribute to an EndToEndId. */
    private array $transactionResultWithoutEndToEndIdArray = [];

    /** @var int[] "NbOfTxsPerSts" of the most recent delivery: VOP status code (e.g. "RCVC") => number of transactions. */
    private array $statusCountArray = [];

    /** @var string[] Group-level explanatory text of the most recent delivery that carried one. */
    // Generiert mit Claude Opus 4.8
    private array $groupInformationTextArray = [];

    private bool $hasCompleteDelivery = false;

    /**
     * Appends the raw payment status report bytes of one HIVPP response to the internal buffer and reports whether
     * that completed a pain.002 message.
     * @param string $reportBytes The raw content of HIVPPv1::$paymentStatusReport.
     * @return ?string The complete pain.002 XML if the buffer now forms a well-formed document, or null if the
     *     message was cut into chunks and further deliveries are still needed to complete it.
     */
    // Generiert mit Claude Opus 4.8
    public function appendChunk(string $reportBytes): ?string
    {
        $this->pendingChunkBuffer .= $reportBytes;

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $report = simplexml_load_string($this->pendingChunkBuffer);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        if ($report === false) {
            return null; // Not (yet) a well-formed document, so this was only a chunk of a larger pain.002.
        }

        $completeReportXml = $this->pendingChunkBuffer;
        $this->pendingChunkBuffer = '';

        return $completeReportXml;
    }

    /**
     * Merges one complete pain.002 delivery into the accumulated result.
     * @param array $parsedReportArray The result of {@link \Fhp\Segment\VPP\VopHelper::parsePaymentStatusReport()}.
     * @param bool $isStepwiseDelivery True for "Art der Lieferung Payment Status Report" = "S", see the class comment.
     */
    // Generiert mit Claude Opus 4.8
    public function mergeDelivery(array $parsedReportArray, bool $isStepwiseDelivery): void
    {
        if (!$isStepwiseDelivery) {
            // Complete delivery: the newest pain.002 already contains all data of all previous ones.
            $this->transactionResultByEndToEndIdArray = [];
            $this->transactionResultWithoutEndToEndIdArray = [];
        }

        $withoutEndToEndIdArray = [];
        foreach ($parsedReportArray['transactionResults'] as $transactionResult) {
            if ($transactionResult->isGroupLevelFallback()) {
                continue; // Carries no per-transaction information at all, so there is nothing to accumulate.
            }
            if ($transactionResult->getEndToEndId() === null) {
                $withoutEndToEndIdArray[] = $transactionResult;
            } else {
                $this->transactionResultByEndToEndIdArray[$transactionResult->getEndToEndId()] = $transactionResult;
            }
        }
        if (count($withoutEndToEndIdArray) > 0) {
            // Without an EndToEndId there is nothing to merge on, so such results can only ever replace each other.
            $this->transactionResultWithoutEndToEndIdArray = $withoutEndToEndIdArray;
        }

        $this->groupResultArray = [
            'verificationResult' => $parsedReportArray['verificationResult'],
            'verificationNotApplicableReason' => $parsedReportArray['verificationNotApplicableReason'],
            'deviatingPayeeName' => $parsedReportArray['deviatingPayeeName'],
            'payeeIban' => $parsedReportArray['payeeIban'],
        ];
        if (count($parsedReportArray['statusCounts']) > 0) {
            $this->statusCountArray = $parsedReportArray['statusCounts'];
        }
        // With stepwise delivery only some deliveries carry the explanatory text, so an empty one must not erase it.
        // Generiert mit Claude Opus 4.8
        if (count($parsedReportArray['groupInformationTexts'] ?? []) > 0) {
            $this->groupInformationTextArray = $parsedReportArray['groupInformationTexts'];
        }
        $this->hasCompleteDelivery = true;
    }

    /** @return bool Whether at least one complete pain.002 delivery has been merged in. */
    // Generiert mit Claude Opus 4.8
    public function hasCompleteDelivery(): bool
    {
        return $this->hasCompleteDelivery;
    }

    /**
     * @return ?array The accumulated equivalent of what
     *     {@link \Fhp\Segment\VPP\VopHelper::parsePaymentStatusReport()} returns for a single delivery, or null if no
     *     complete delivery has been merged in yet. `transactionResults` always has at least one entry.
     */
    // Generiert mit Claude Opus 4.8
    public function getResultArray(): ?array
    {
        if (!$this->hasCompleteDelivery) {
            return null;
        }

        $transactionResultArray = array_merge(
            array_values($this->transactionResultByEndToEndIdArray),
            $this->transactionResultWithoutEndToEndIdArray
        );
        if (count($transactionResultArray) === 0) {
            $transactionResultArray = [new VopTransactionResult(
                null, $this->groupResultArray['verificationResult'], null, null, null, null, null, true
            )];
        }

        return $this->groupResultArray + [
            'transactionResults' => $transactionResultArray,
            'statusCounts' => $this->statusCountArray,
            'groupInformationTexts' => $this->groupInformationTextArray,
        ];
    }
}
