<?php

namespace Fhp\Segment\VPP;

use Fhp\Model\VopConfirmationRequestImpl;
use Fhp\Model\VopPollingInfo;
use Fhp\Model\VopReportAccumulator;
use Fhp\Model\VopTransactionResult;
use Fhp\Model\VopVerificationResult;
use Fhp\Protocol\BPD;
use Fhp\Protocol\Message;
use Fhp\Protocol\UnexpectedResponseException;
use Fhp\Segment\HIRMS\Rueckmeldungscode;
use Fhp\Segment\VPA\HKVPAv1;
use Fhp\UnsupportedException;

/**
 * Creates request segments and interprets response segments and Ruckmeldungscodes for anything related to VOP.
 * @see FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01_2025_06_27_FV.pdf
 */
class VopHelper
{
    /** Maximum value that fits into HKVPPv1::$maximaleAnzahlEintraege (DE num ..4 per the spec). */
    // Generiert mit Claude Opus 4.8
    private const MAX_ANZAHL_EINTRAEGE = 9999;

    /** The VOP status codes a bank may use to prefix a group-level explanatory text, see {@link extractGroupInformationTexts()}. */
    // Generiert mit Claude Opus 4.8
    private const VOP_STATUS_CODES = ['RCVC', 'RVMC', 'RVNM', 'RVCM', 'RVNA', 'PDNG'];

    /**
     * @param BPD $bpd The BPD.
     * @param int|null $maxEntries The number of transactions in the payment order this HKVPP accompanies, used as the
     *     page size for the VOP result delivery ("Maximale Anzahl Einträge"), so that the bank can return the whole
     *     result in one response instead of forcing extra Aufsetzpunkt round trips. Only sent if the bank allows it
     *     ("Eingabe Anzahl Einträge erlaubt" in HIVPPS). Pass null to leave the choice entirely to the bank.
     * @return HKVPPv1 A segment to prompt the server to do Verification of Payee.
     */
    public static function createHKVPPForInitialRequest(BPD $bpd, ?int $maxEntries = null): HKVPPv1
    {
        // For now just pretend we support all formats.
        /** @var HIVPPSv1 $hivpps */
        $hivpps = $bpd->getLatestSupportedParameters('HIVPPS');
        $supportedFormats = explode(';', $hivpps->parameter->unterstuetztePaymentStatusReportDatenformate);

        $hkvpp = HKVPPv1::createEmpty();
        $hkvpp->unterstuetztePaymentStatusReports->paymentStatusReportDescriptor = $supportedFormats;
        // Both "V" (vollständig) and "S" (schrittweise) delivery are supported, see VopReportAccumulator.
        // Generiert mit Claude Opus 4.8
        if ($maxEntries !== null && $maxEntries > 0 && $hivpps->parameter->eingabeAnzahlEintraegeErlaubt) {
            $hkvpp->maximaleAnzahlEintraege = min($maxEntries, static::MAX_ANZAHL_EINTRAEGE);
        }
        return $hkvpp;
    }

    /**
     * @param BPD $bpd The BPD.
     * @param VopPollingInfo $pollingInfo The polling info we got from the immediately preceding request.
     * @param int|null $maxEntries See {@link createHKVPPForInitialRequest()}.
     * @return HKVPPv1 A segment to poll the server for the completion of Verification of Payee.
     */
    public static function createHKVPPForPollingRequest(BPD $bpd, VopPollingInfo $pollingInfo, ?int $maxEntries = null): HKVPPv1
    {
        $hkvpp = static::createHKVPPForInitialRequest($bpd, $maxEntries);
        $hkvpp->aufsetzpunkt = $pollingInfo->getAufsetzpunkt();
        $hkvpp->pollingId = $pollingInfo->getPollingId();
        return $hkvpp;
    }

    /**
     * Feeds the payment status report of one HIVPP response (which may be only a chunk of a larger pain.002, and/or
     * only one of several stepwise deliveries) into the accumulator that collects the overall VOP result across all
     * Aufsetzpunkt round trips. Must be called for every HIVPP that carries a payment status report, including the
     * final one, before {@link checkVopConfirmationRequired()}.
     * @param BPD $bpd The BPD, used to read the bank's "Art der Lieferung Payment Status Report".
     * @param HIVPPv1 $hivpp The HIVPP segment of the response we just received.
     * @param VopReportAccumulator $accumulator The accumulator of the action this response belongs to.
     */
    // Generiert mit Claude Opus 4.8
    public static function accumulatePaymentStatusReport(BPD $bpd, HIVPPv1 $hivpp, VopReportAccumulator $accumulator): void
    {
        if ($hivpp->paymentStatusReport === null) {
            return;
        }

        $completeReportXml = $accumulator->appendChunk($hivpp->paymentStatusReport->getData());
        if ($completeReportXml === null) {
            return; // The pain.002 was cut into chunks and has not been fully transmitted yet.
        }

        $accumulator->mergeDelivery(static::parsePaymentStatusReport($completeReportXml), static::isStepwiseDelivery($bpd));
    }

    /**
     * @param BPD $bpd The BPD.
     * @return bool Whether the bank delivers the payment status report stepwise ("S") rather than completely ("V").
     */
    // Generiert mit Claude Opus 4.8
    private static function isStepwiseDelivery(BPD $bpd): bool
    {
        /** @var ?HIVPPSv1 $hivpps */
        $hivpps = $bpd->getLatestSupportedParameters('HIVPPS');
        return $hivpps?->parameter?->artDerLieferungPaymentStatusReport === 'S';
    }

    /**
     * @param Message $response The response we just received from the server.
     * @param int $hkvppSegmentNumber The number of the HKVPP segment in the request we had sent.
     * @return ?VopPollingInfo If the response indicates that the Verification of Payee is still ongoing, such that the
     *     client should keep polling the server to (actively) wait until the result is available, this function returns
     *     a corresponding polling info object. If no polling is required, it returns null.
     */
    public static function checkPollingRequired(Message $response, int $hkvppSegmentNumber): ?VopPollingInfo
    {
        // Note: We determine whether polling is required purely based on the presence of the primary polling token (
        // the Aufsetzpunkt is mandatory, the polling ID is optional).
        // The specification also contains the code "3093 Namensabgleich ist noch in Bearbeitung", which could also be
        // used to indicate that polling is required. But the specification does not mandate its use, and we have not
        // observed banks using it consistently, so we don't rely on it here.
        $aufsetzpunkt = $response->findRueckmeldung(Rueckmeldungscode::AUFSETZPUNKT, $hkvppSegmentNumber);
        if ($aufsetzpunkt === null) {
            return null;
        }
        /** @var ?HIVPPv1 $hivpp */
        $hivpp = $response->findSegment(HIVPPv1::class);
        // Note: An Aufsetzpunkt together with a payment status report is the normal case, not an error - each
        // Teillieferung carries (part of) a pain.002 and only the final one carries the VOP-ID, see the example in
        // FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01, chapter D and VopReportAccumulator. An Aufsetzpunkt
        // together with a VOP-ID, on the other hand, contradicts "Bei Aufsetzpunktbehandlung ist das jeweilige Feld
        // erst im abschließend gelieferten HIVPP einzustellen".
        // Generiert mit Claude Opus 4.8
        if ($hivpp?->vopId !== null) {
            throw new UnexpectedResponseException('Got response with Aufsetzpunkt AND vopId.');
        }
        return new VopPollingInfo(
            $aufsetzpunkt->rueckmeldungsparameter[0],
            $hivpp?->pollingId,
            $hivpp?->wartezeitVorNaechsterAbfrage,
        );
    }

    /**
     * @param Message $response The response we just received from the server.
     * @param int $hkvppSegmentNumber The number of the HKVPP segment in the request we had sent.
     * @param ?VopReportAccumulator $accumulator The accumulator that {@link accumulatePaymentStatusReport()} has been
     *     fed with for every HIVPP of this action, or null if the bank never sent a payment status report at all.
     * @return ?VopConfirmationRequestImpl If the response contains a confirmation request for the user, it is returned,
     *     otherwise null (which may imply that the action was executed without requiring confirmation).
     */
    public static function checkVopConfirmationRequired(
        Message $response,
        int $hkvppSegmentNumber,
        ?VopReportAccumulator $accumulator = null,
    ): ?VopConfirmationRequestImpl {
        $codes = $response->findRueckmeldungscodesForReferenceSegment($hkvppSegmentNumber);
        if (in_array(Rueckmeldungscode::VOP_AUSFUEHRUNGSAUFTRAG_NICHT_BENOETIGT, $codes)) {
            return null;
        }
        /** @var HIVPPv1 $hivpp */
        $hivpp = $response->findSegment(HIVPPv1::class);
        if ($hivpp === null) {
            throw new UnexpectedResponseException('Missing HIVPP in response to a request with HKVPP');
        }
        if ($hivpp->vopId === null) {
            throw new UnexpectedResponseException('Missing HIVPP.vopId even though VOP should be completed.');
        }

        $statusCountArray = [];
        $groupInformationTextArray = [];
        // Note: With stepwise delivery the final HIVPP (the one carrying the VOP-ID) may well have no payment status
        // report of its own anymore, because all of the data arrived in earlier Teillieferungen - hence the result is
        // taken from the accumulator rather than from this response.
        // Generiert mit Claude Opus 4.8
        $hasAccumulatedReport = $accumulator !== null && $accumulator->hasCompleteDelivery();
        if (!$hasAccumulatedReport) {
            if ($hivpp->ergebnisVopPruefungEinzeltransaktion === null) {
                throw new UnsupportedException('Missing paymentStatusReport and ergebnisVopPruefungEinzeltransaktion');
            }
            // Only populated for the DEG-based single-transaction report; the pain.002 report (Anlage 3 DFÜ-Abkommen) does
            // not carry these fields, see FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01.pdf, Kapitel D.
            // Generiert mit Claude Opus 4.8
            $verificationResult = VopVerificationResult::parse(
                $hivpp->ergebnisVopPruefungEinzeltransaktion->vopPruefergebnis
            );
            $verificationNotApplicableReason = $hivpp->ergebnisVopPruefungEinzeltransaktion->grundRVNA;
            // Per spec, "Abweichender Empfängername" MUST be shown to the payer as a decision aid on Close Match.
            // Generiert mit Claude Opus 4.8
            $deviatingPayeeName = $hivpp->ergebnisVopPruefungEinzeltransaktion->abweichenderEmpfaengername;
            $payeeIban = $hivpp->ergebnisVopPruefungEinzeltransaktion->ibanEmpfaenger;
            $payeeIbanAdditionalInformation = $hivpp->ergebnisVopPruefungEinzeltransaktion->ibanZusatzinformationen;
            $otherIdentificationFeature = $hivpp->ergebnisVopPruefungEinzeltransaktion->anderesIdentifikationmerkmal;

            // This DEG report has no concept of a batch/EndToEndId at all - it is inherently single-transaction.
            // Generiert mit Claude Opus 4.8
            $transactionResults = [new VopTransactionResult(
                null,
                $verificationResult,
                $verificationNotApplicableReason,
                $deviatingPayeeName,
                $payeeIban,
                $payeeIbanAdditionalInformation,
                $otherIdentificationFeature,
            )];
        } else {
            $reportResult = $accumulator->getResultArray();
            $verificationResult = $reportResult['verificationResult'];
            $verificationNotApplicableReason = $reportResult['verificationNotApplicableReason'];
            $deviatingPayeeName = $reportResult['deviatingPayeeName'];
            $payeeIban = $reportResult['payeeIban'];
            $payeeIbanAdditionalInformation = null;
            $otherIdentificationFeature = null;
            $transactionResults = $reportResult['transactionResults'];
            $statusCountArray = $reportResult['statusCounts'];
            $groupInformationTextArray = $reportResult['groupInformationTexts'];
        }

        return new VopConfirmationRequestImpl(
            $hivpp->vopId,
            $hivpp->vopIdGueltigBis?->asDateTime(),
            $hivpp->aufklaerungstextAutorisierungTrotzAbweichung,
            $verificationResult,
            $verificationNotApplicableReason,
            $deviatingPayeeName,
            $payeeIban,
            $payeeIbanAdditionalInformation,
            $otherIdentificationFeature,
            $transactionResults,
            $statusCountArray,
            $groupInformationTextArray,
        );
    }

    /**
     * Parses a pain.002 (CstmrPmtStsRpt) payment status report XML string into the aggregate ("primary", group-level)
     * VOP result plus one {@link VopTransactionResult} per original transaction, when the report carries that level
     * of detail. Extracted as its own function (independent of {@link Message}/{@link BPD}) so it can be exercised
     * with synthetic XML strings directly, without needing to construct a full FinTS response.
     * @param string $painXml The raw pain.002 XML, as delivered in HIVPPv1::$paymentStatusReport.
     * @return array{verificationResult: ?string, verificationNotApplicableReason: ?string, deviatingPayeeName: ?string,
     *     payeeIban: ?string, transactionResults: VopTransactionResult[], statusCounts: int[],
     *     groupInformationTexts: string[]} transactionResults
     *     always has at least one entry; if no per-transaction detail could be attributed, it has exactly one entry
     *     with {@link VopTransactionResult::isGroupLevelFallback()} true. statusCounts is the group-level
     *     "NbOfTxsPerSts" breakdown (VOP status code => number of transactions), empty if the bank did not report it.
     *     groupInformationTexts is the group-level explanatory text, see {@link extractGroupInformationTexts()}.
     */
    // Generiert mit Claude Opus 4.8
    public static function parsePaymentStatusReport(string $painXml): array
    {
        $report = simplexml_load_string($painXml);
        $verificationResult = VopVerificationResult::parse(
            $report->CstmrPmtStsRpt->OrgnlGrpInfAndSts->GrpSts ?: null
        );
        $statusCountArray = static::extractStatusCounts($report->CstmrPmtStsRpt->OrgnlGrpInfAndSts ?? null);
        $groupInformationTextArray = static::extractGroupInformationTexts(
            $report->CstmrPmtStsRpt->OrgnlGrpInfAndSts->StsRsnInf ?? null
        );
        $verificationNotApplicableReason = null;
        $deviatingPayeeName = null;
        $payeeIban = null;

        $orgnlNbOfTxs = intval($report->CstmrPmtStsRpt->OrgnlGrpInfAndSts->OrgnlNbOfTxs ?: 0);
        $txInfAndStsList = $report->CstmrPmtStsRpt->OrgnlPmtInfAndSts->TxInfAndSts ?? null;
        $hasTxInfAndSts = ($txInfAndStsList !== null && count($txInfAndStsList) > 0);

        if ($orgnlNbOfTxs === 1 && $hasTxInfAndSts) {
            // For a single transaction, extract EndToEndId regardless of the result, so single- and multi-payment
            // reports produce the same transactionResults shape for the caller.
            // Generiert mit Claude Opus 4.8
            $txInfAndSts = $txInfAndStsList[0];
            $endToEndId = static::extractOriginalEndToEndId($txInfAndSts);

            // For a single transaction, we can do better than "CompletedPartialMatch", which can indicate either
            // CompletedCloseMatch or CompletedNoMatch. In this case, the report also carries transaction-level detail
            // (StsRsnInf/AddtlInf, OrgnlTxRef) that is not available at the group level.
            // Generiert mit Claude Opus 4.8
            if ($verificationResult === VopVerificationResult::CompletedPartialMatch && ($verificationResultCode = $txInfAndSts->TxSts)) {
                $verificationResult = VopVerificationResult::parse($verificationResultCode);

                // Per FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01, Kapitel D / Anlage 3 DFÜ-Abkommen:
                // on Close Match, some banks report the name on file as free text in
                // OrgnlPmtInfAndSts/TxInfAndSts/StsRsnInf/AddtlInf. Note that OrgnlTxRef/Cdtr/Pty/Nm below is
                // NOT that corrected name - it only echoes back the name/IBAN we originally submitted.
                // Generiert mit Claude Opus 4.8
                $correctedName = static::extractAddtlInfText($txInfAndSts->StsRsnInf ?? null);
                if ($correctedName !== '') {
                    $deviatingPayeeName = $correctedName;
                }

                $payeeIban = (string)($txInfAndSts->OrgnlTxRef->CdtrAcct->Id->IBAN ?? '') ?: null;

                // Pain.002 equivalent of the DEG-based case's grundRVNA above: when verification could not be
                // completed (TxSts=RVNA), the ISO 20022 reason code (e.g. "AG03") is carried per-transaction in
                // StsRsnInf/Rsn/Cd instead of free text.
                // Generiert mit Claude Opus 4.8
                if ($verificationResult === VopVerificationResult::NotApplicable) {
                    $verificationNotApplicableReason = static::extractReasonCode($txInfAndSts->StsRsnInf ?? null);
                }
            }

            $transactionResults = [new VopTransactionResult(
                $endToEndId,
                $verificationResult,
                $verificationNotApplicableReason,
                $deviatingPayeeName,
                $payeeIban,
                null,
                null,
            )];
        } elseif ($orgnlNbOfTxs === 1
            && !$hasTxInfAndSts
            && $verificationResult === VopVerificationResult::CompletedPartialMatch
            && $pmtInfSts = (string)($report->CstmrPmtStsRpt->OrgnlPmtInfAndSts->PmtInfSts ?? '')
        ) {
            // Some banks (e.g. HypoVereinsbank, see hbci4java's ParsePain00200110) omit TxInfAndSts entirely and
            // report the per-transaction result only via OrgnlPmtInfAndSts/PmtInfSts. No StsRsnInf/OrgnlTxRef/
            // EndToEndId is available at this level, so those stay unset in this branch.
            // Generiert mit Claude Opus 4.8
            $verificationResult = VopVerificationResult::parse($pmtInfSts);
            $transactionResults = [new VopTransactionResult(null, $verificationResult)];
        } elseif ($orgnlNbOfTxs > 1 && $hasTxInfAndSts) {
            // Real batch with per-transaction detail: one VopTransactionResult per TxInfAndSts, correlated back to
            // the original CdtTrfTxInf/DrctDbtTxInf via EndToEndId.
            // Generiert mit Claude Opus 4.8
            $transactionResults = [];
            foreach ($txInfAndStsList as $txInfAndSts) {
                $txVerificationResult = $txInfAndSts->TxSts
                    ? VopVerificationResult::parse((string)$txInfAndSts->TxSts)
                    : $verificationResult;
                $txDeviatingPayeeName = static::extractAddtlInfText($txInfAndSts->StsRsnInf ?? null);
                $txNotApplicableReason = ($txVerificationResult === VopVerificationResult::NotApplicable)
                    ? static::extractReasonCode($txInfAndSts->StsRsnInf ?? null)
                    : null;

                $transactionResults[] = new VopTransactionResult(
                    static::extractOriginalEndToEndId($txInfAndSts),
                    $txVerificationResult,
                    $txNotApplicableReason,
                    $txDeviatingPayeeName !== '' ? $txDeviatingPayeeName : null,
                    (string)($txInfAndSts->OrgnlTxRef->CdtrAcct->Id->IBAN ?? '') ?: null,
                    null,
                    null,
                );
            }
        } else {
            // Bank only reported a group-level status for the whole batch (or OrgnlNbOfTxs is unknown/0) - no
            // per-transaction detail is available at all. Callers must expand this single fallback result across
            // every submitted payment themselves, since there is no EndToEndId to correlate against.
            // Generiert mit Claude Opus 4.8
            $transactionResults = [new VopTransactionResult(null, $verificationResult, null, null, null, null, null, true)];
        }

        return [
            'verificationResult' => $verificationResult,
            'verificationNotApplicableReason' => $verificationNotApplicableReason,
            'deviatingPayeeName' => $deviatingPayeeName,
            'payeeIban' => $payeeIban,
            'transactionResults' => $transactionResults,
            'statusCounts' => $statusCountArray,
            'groupInformationTexts' => $groupInformationTextArray,
        ];
    }

    /**
     * Extracts the group-level explanatory text ("Aufklärungstext") from OrgnlGrpInfAndSts/StsRsnInf/AddtlInf. This is
     * NOT transaction data - it is the boilerplate the bank wants shown to the payer before they authorize despite a
     * deviation, the pain.002 counterpart of HIVPP's "Aufklaerungstext Autorisierung trotz Abweichung" (which banks
     * that report via pain.002 tend to leave empty). Never mistake it for a corrected payee name, see the class of
     * traps described in VOP.md.
     *
     * Two bank-specific quirks are handled here:
     *  - Each line MAY be prefixed with the VOP status code it explains ("RVNM ..."). Banks that send a generic legend
     *    (e.g. Atruvia/Fiducia) explain several codes this way, so lines are grouped by that code.
     *  - AddtlInf is capped at 105 characters, so a longer text is cut into several lines - and the cut happens
     *    mid-word ("...Ueberweisungsbetra" / "g auf ein..."). Lines of the same code are therefore concatenated
     *    WITHOUT any separator and without trimming the individual pieces, otherwise words get glued together or torn
     *    apart at the boundary.
     *
     * @param ?\SimpleXMLElement $stsRsnInf The (possibly repeated) group-level StsRsnInf node(s), or null if absent.
     * @return string[] One reassembled text per explained status code, in order of first appearance; empty if the
     *     bank did not send any group-level explanatory text.
     */
    // Generiert mit Claude Opus 4.8
    private static function extractGroupInformationTexts(?\SimpleXMLElement $stsRsnInf): array
    {
        if ($stsRsnInf === null) {
            return [];
        }

        $textByStatusCodeArray = [];
        foreach ($stsRsnInf as $reasonInfo) {
            foreach ($reasonInfo->AddtlInf ?? [] as $line) {
                $line = (string)$line;
                $statusCode = '';
                foreach (static::VOP_STATUS_CODES as $vopStatusCode) {
                    if (str_starts_with($line, "$vopStatusCode ")) {
                        $statusCode = $vopStatusCode;
                        $line = substr($line, strlen($vopStatusCode) + 1);
                        break;
                    }
                }
                $textByStatusCodeArray[$statusCode] = ($textByStatusCodeArray[$statusCode] ?? '') . $line;
            }
        }

        $textArray = array_map('trim', array_values($textByStatusCodeArray));

        return array_values(array_filter($textArray, fn (string $text) => $text !== ''));
    }

    /**
     * Extracts the EndToEndId of the original transaction that one TxInfAndSts block refers back to. In pain.002 this
     * is the direct child element "OrgnlEndToEndId" of TxInfAndSts (PaymentTransaction105 in pain.002.001.10,
     * PaymentTransactionInformation25 in pain.002.001.03), NOT a nested OrgnlTxRef/PmtId/EndToEndId - the
     * OrgnlTxRef type (OriginalTransactionReference28) has no PmtId element at all, so that path can never match.
     * Without this ID the per-transaction results of a batch cannot be correlated back to the submitted payments.
     * @param \SimpleXMLElement $txInfAndSts One TxInfAndSts block of the report.
     * @return ?string The original EndToEndId, or null if the bank did not report one.
     */
    // Generiert mit Claude Opus 4.8
    private static function extractOriginalEndToEndId(\SimpleXMLElement $txInfAndSts): ?string
    {
        return (string)($txInfAndSts->OrgnlEndToEndId ?? '') ?: null;
    }

    /**
     * Extracts the group-level "NumberOfTransactionsPerStatus" breakdown, which the spec describes as the overview of
     * "die Anzahl aller bisher erhaltener einzelnen Statusmeldungen" per status for the whole pain.001. It is what
     * makes it possible to tell how many transactions ended up as Match (RCVC) even though a delta/stepwise report
     * only lists the deviating ones individually.
     * @param ?\SimpleXMLElement $orgnlGrpInfAndSts The OrgnlGrpInfAndSts node, or null if absent.
     * @return int[] VOP status code (e.g. "RCVC") => number of transactions; empty if the bank did not report it.
     */
    // Generiert mit Claude Opus 4.8
    private static function extractStatusCounts(?\SimpleXMLElement $orgnlGrpInfAndSts): array
    {
        if ($orgnlGrpInfAndSts === null) {
            return [];
        }

        $statusCountArray = [];
        foreach ($orgnlGrpInfAndSts->NbOfTxsPerSts ?? [] as $numberPerStatus) {
            $status = (string)($numberPerStatus->DtldSts ?? '');
            if ($status === '') {
                continue;
            }
            $statusCountArray[$status] = ($statusCountArray[$status] ?? 0) + intval($numberPerStatus->DtldNbOfTxs ?? 0);
        }

        return $statusCountArray;
    }

    /**
     * Concatenates the free-text AddtlInf lines of one or more StsRsnInf blocks, stripping a leading quote
     * character from each line - mirrors how established FinTS clients (e.g. Hibiscus/HBCI4Java's
     * ParsePain00200110) extract the bank-corrected payee name that some banks put here on VOP Close Match.
     * @param ?\SimpleXMLElement $stsRsnInf The (possibly repeated) StsRsnInf node(s), or null if absent.
     * @return string The concatenated text, or '' if none was present.
     */
    // Generiert mit Claude Opus 4.8
    private static function extractAddtlInfText(?\SimpleXMLElement $stsRsnInf): string
    {
        if ($stsRsnInf === null) {
            return '';
        }

        $text = '';
        foreach ($stsRsnInf as $reasonInfo) {
            foreach ($reasonInfo->AddtlInf ?? [] as $line) {
                $line = trim((string)$line);
                foreach (["\"", "\u{201C}"] as $quote) { // straight and curly opening quote
                    if (str_starts_with($line, $quote)) {
                        $line = substr($line, strlen($quote));
                        break;
                    }
                }
                $text .= $line;
            }
        }

        return $text;
    }

    /**
     * Extracts the first ISO 20022 external status reason code (e.g. "AG03") from one or more StsRsnInf blocks.
     * @param ?\SimpleXMLElement $stsRsnInf The (possibly repeated) StsRsnInf node(s), or null if absent.
     * @return ?string The first reason code found, or null if none was present.
     */
    // Generiert mit Claude Opus 4.8
    private static function extractReasonCode(?\SimpleXMLElement $stsRsnInf): ?string
    {
        if ($stsRsnInf === null) {
            return null;
        }

        foreach ($stsRsnInf as $reasonInfo) {
            $code = (string)($reasonInfo->Rsn->Cd ?? '');
            if ($code !== '') {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param VopConfirmationRequestImpl $vopConfirmationRequest The VOP request we're confirming.
     * @return HKVPAv1 A HKVPA segment that tells the bank the request is good to execute.
     */
    public static function createHKVPAForConfirmation(VopConfirmationRequestImpl $vopConfirmationRequest): HKVPAv1
    {
        $hkvpa = HKVPAv1::createEmpty();
        $hkvpa->vopId = $vopConfirmationRequest->getVopId();
        return $hkvpa;
    }
}
