<?php

namespace Fhp\Segment\VPP;

use Fhp\Model\VopConfirmationRequestImpl;
use Fhp\Model\VopPollingInfo;
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
    /**
     * @param BPD $bpd The BPD.
     * @return HKVPPv1 A segment to prompt the server to do Verification of Payee.
     */
    public static function createHKVPPForInitialRequest(BPD $bpd): HKVPPv1
    {
        // For now just pretend we support all formats.
        /** @var HIVPPSv1 $hivpps */
        $hivpps = $bpd->getLatestSupportedParameters('HIVPPS');
        $supportedFormats = explode(';', $hivpps->parameter->unterstuetztePaymentStatusReportDatenformate);
        if ($hivpps->parameter->artDerLieferungPaymentStatusReport !== 'V') {
            throw new UnsupportedException('The stepwise transfer of VOP reports is not yet supported');
        }

        $hkvpp = HKVPPv1::createEmpty();
        $hkvpp->unterstuetztePaymentStatusReports->paymentStatusReportDescriptor = $supportedFormats;
        return $hkvpp;
    }

    /**
     * @param BPD $bpd The BPD.
     * @param VopPollingInfo $pollingInfo The polling info we got from the immediately preceding request.
     * @return HKVPPv1 A segment to poll the server for the completion of Verification of Payee.
     */
    public static function createHKVPPForPollingRequest(BPD $bpd, VopPollingInfo $pollingInfo): HKVPPv1
    {
        $hkvpp = static::createHKVPPForInitialRequest($bpd);
        $hkvpp->aufsetzpunkt = $pollingInfo->getAufsetzpunkt();
        $hkvpp->pollingId = $pollingInfo->getPollingId();
        return $hkvpp;
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
        /** @var HIVPPv1 $hivpp */
        $hivpp = $response->findSegment(HIVPPv1::class);
        if ($hivpp->vopId !== null || $hivpp->paymentStatusReport !== null) {
            // Implementation note: If this ever happens, it could be related to $artDerLieferungPaymentStatusReport.
            throw new UnexpectedResponseException('Got response with Aufsetzpunkt AND vopId/paymentStatusReport.');
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
     * @return ?VopConfirmationRequestImpl If the response contains a confirmation request for the user, it is returned,
     *     otherwise null (which may imply that the action was executed without requiring confirmation).
     */
    public static function checkVopConfirmationRequired(
        Message $response,
        int $hkvppSegmentNumber,
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

        $verificationNotApplicableReason = null;
        // Only populated for the DEG-based single-transaction report; the pain.002 report (Anlage 3 DFÜ-Abkommen) does
        // not carry these fields, see FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01.pdf, Kapitel D.
        // Generiert mit Claude Opus 4.8
        $deviatingPayeeName = null;
        $payeeIban = null;
        $payeeIbanAdditionalInformation = null;
        $otherIdentificationFeature = null;
        if ($hivpp->paymentStatusReport === null) {
            if ($hivpp->ergebnisVopPruefungEinzeltransaktion === null) {
                throw new UnsupportedException('Missing paymentStatusReport and ergebnisVopPruefungEinzeltransaktion');
            }
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
        } else {
            $report = simplexml_load_string($hivpp->paymentStatusReport->getData());
            $verificationResult = VopVerificationResult::parse(
                $report->CstmrPmtStsRpt->OrgnlGrpInfAndSts->GrpSts ?: null
            );

            // For a single transaction, we can do better than "CompletedPartialMatch",
            // which can indicate either CompletedCloseMatch or CompletedNoMatch. In this case, the
            // report also carries transaction-level detail (StsRsnInf/AddtlInf, OrgnlTxRef) that is
            // not available at the group level.
            // Generiert mit Claude Opus 4.8
            $txInfAndSts = $report->CstmrPmtStsRpt->OrgnlPmtInfAndSts->TxInfAndSts ?? null;
            if (intval($report->CstmrPmtStsRpt->OrgnlGrpInfAndSts->OrgnlNbOfTxs ?: 0) === 1
                && $verificationResult === VopVerificationResult::CompletedPartialMatch
                && $txInfAndSts !== null
                && $verificationResultCode = $txInfAndSts->TxSts
            ) {
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
            } elseif (intval($report->CstmrPmtStsRpt->OrgnlGrpInfAndSts->OrgnlNbOfTxs ?: 0) === 1
                && $verificationResult === VopVerificationResult::CompletedPartialMatch
                && ($txInfAndSts === null || count($txInfAndSts) === 0)
                && $pmtInfSts = (string)($report->CstmrPmtStsRpt->OrgnlPmtInfAndSts->PmtInfSts ?? '')
            ) {
                // Some banks (e.g. HypoVereinsbank, see hbci4java's ParsePain00200110) omit TxInfAndSts entirely and
                // report the per-transaction result only via OrgnlPmtInfAndSts/PmtInfSts. No StsRsnInf/OrgnlTxRef is
                // available at this level, so name/IBAN/reason stay unset in this branch.
                // Generiert mit Claude Opus 4.8
                $verificationResult = VopVerificationResult::parse($pmtInfSts);
            }
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
        );
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
