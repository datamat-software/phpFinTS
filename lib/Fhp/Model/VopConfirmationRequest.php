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
}
