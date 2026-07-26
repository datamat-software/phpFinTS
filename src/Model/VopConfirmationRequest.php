<?php

namespace Fhp\Model;

/**
 * Provides information (about the payee) that the client application should present to the user and then ask for their
 * confirmation that the transfer (to this payee) should be executed.
 */
interface VopConfirmationRequest
{
    /** An HTML-formatted text that (if present) the application must show to the user when asking for confirmation. */
    public function getInformationForUser(): ?string;

    /** If this returns a non-null value, the confirmation request is only valid up to that time. */
    public function getExpiration(): ?\DateTime;

    /** The main outcome of the payee verification. See {@link VopVerificationResult} for possible values. */
    public function getVerificationResult(): ?string;

    /**
     * If {@link getVerificationResult()} returns {@link VopVerificationResult::NotApplicable}, then this function MAY
     * return an additional explanation (in the user's language or in English), but it may also return null.
     */
    public function getVerificationNotApplicableReason(): ?string;

    /**
     * The name/company name that is on file with the payee's account-holding institution, as reported by the bank.
     * Per spec (FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01, chapter D, field "Abweichender Empfängername"), this
     * MUST be shown to the payer as a decision aid whenever {@link getVerificationResult()} returns
     * {@link VopVerificationResult::CompletedCloseMatch}. Only available when the bank reported the result via the
     * "Ergebnis VOP-Prüfung Einzeltransaktion" DEG (single-transaction case); null when reported via pain.002.
     */
    // Generiert mit Claude Opus 4.8
    public function getDeviatingPayeeName(): ?string;

    /**
     * The IBAN of the payee that this verification result refers to. Only available when the bank reported the
     * result via the "Ergebnis VOP-Prüfung Einzeltransaktion" DEG (single-transaction case); null when reported via
     * pain.002.
     */
    // Generiert mit Claude Opus 4.8
    public function getPayeeIban(): ?string;

    /**
     * Additional information to further specify the payee account (e.g. sub-account number), as reported by the
     * bank. Only available when the bank reported the result via the "Ergebnis VOP-Prüfung Einzeltransaktion" DEG.
     */
    // Generiert mit Claude Opus 4.8
    public function getPayeeIbanAdditionalInformation(): ?string;

    /**
     * If the bank checked an identification feature other than the name (e.g. LEI), this contains the name of that
     * checked ID. Only available when the bank reported the result via the "Ergebnis VOP-Prüfung Einzeltransaktion"
     * DEG.
     */
    // Generiert mit Claude Opus 4.8
    public function getOtherIdentificationFeature(): ?string;

    /**
     * The per-transaction VOP results, one {@link VopTransactionResult} per original transaction in the batch this
     * action submitted (also populated with exactly one entry for a single-transaction action, so callers can treat
     * both cases uniformly). Correlate each entry back to the original transaction via
     * {@link VopTransactionResult::getEndToEndId()}. May contain a single entry with
     * {@link VopTransactionResult::isGroupLevelFallback()} true if the bank only reported a group-level result for
     * the whole batch.
     * @return VopTransactionResult[]
     */
    // Generiert mit Claude Opus 4.8
    public function getTransactionResults(): array;

    /**
     * The group-level "NbOfTxsPerSts" breakdown of the bank's pain.002 report: VOP status code (e.g. "RCVC") => number
     * of transactions with that status, across the whole submitted order. Empty if the bank did not report it or did
     * not use a pain.002 at all. This is what allows a caller to tell how many transactions ended up as Match even
     * though {@link getTransactionResults()} may only list the deviating ones individually (see
     * FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01, chapter D: the client ends up with per-transaction detail for
     * exactly those transactions that did NOT get the status Match).
     * @return int[]
     */
    // Generiert mit Claude Opus 4.8
    public function getTransactionStatusCounts(): array;

    /**
     * The bank's group-level explanatory text about authorizing despite a deviating payee, taken from the pain.002
     * report (OrgnlGrpInfAndSts/StsRsnInf/AddtlInf), reassembled from the bank's 105-character line fragments and
     * split into one entry per explained status code. This is the pain.002 counterpart of
     * {@link getInformationForUser()}, which banks reporting via pain.002 tend to leave empty - use it as a fallback
     * for that. It is boilerplate meant for display to the payer, never transaction data and never a payee name.
     * Empty if the bank sent no such text or did not use a pain.002 at all.
     * @return string[]
     */
    // Generiert mit Claude Opus 4.8
    public function getGroupInformationTexts(): array;
}
