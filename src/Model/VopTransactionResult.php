<?php

namespace Fhp\Model;

/**
 * The Verification-of-Payee result for a single transaction within a batch (Sammelüberweisung/-lastschrift).
 * For a single-transaction action, {@link VopConfirmationRequest::getTransactionResults()} still returns exactly
 * one instance of this class, so callers can treat the single- and multi-transaction case uniformly.
 */
// Generiert mit Claude Opus 4.8
class VopTransactionResult
{
    private ?string $endToEndId;
    private ?string $verificationResult;
    private ?string $verificationNotApplicableReason;
    private ?string $deviatingPayeeName;
    private ?string $payeeIban;
    private ?string $payeeIbanAdditionalInformation;
    private ?string $otherIdentificationFeature;
    private bool $isGroupLevelFallback;

    public function __construct(
        ?string $endToEndId,
        ?string $verificationResult,
        ?string $verificationNotApplicableReason = null,
        ?string $deviatingPayeeName = null,
        ?string $payeeIban = null,
        ?string $payeeIbanAdditionalInformation = null,
        ?string $otherIdentificationFeature = null,
        bool $isGroupLevelFallback = false,
    ) {
        $this->endToEndId = $endToEndId;
        $this->verificationResult = $verificationResult;
        $this->verificationNotApplicableReason = $verificationNotApplicableReason;
        $this->deviatingPayeeName = $deviatingPayeeName;
        $this->payeeIban = $payeeIban;
        $this->payeeIbanAdditionalInformation = $payeeIbanAdditionalInformation;
        $this->otherIdentificationFeature = $otherIdentificationFeature;
        $this->isGroupLevelFallback = $isGroupLevelFallback;
    }

    /** The original EndToEndId of the transaction this result refers to, or null if it could not be determined. */
    public function getEndToEndId(): ?string
    {
        return $this->endToEndId;
    }

    public function getVerificationResult(): ?string
    {
        return $this->verificationResult;
    }

    public function getVerificationNotApplicableReason(): ?string
    {
        return $this->verificationNotApplicableReason;
    }

    public function getDeviatingPayeeName(): ?string
    {
        return $this->deviatingPayeeName;
    }

    public function getPayeeIban(): ?string
    {
        return $this->payeeIban;
    }

    public function getPayeeIbanAdditionalInformation(): ?string
    {
        return $this->payeeIbanAdditionalInformation;
    }

    public function getOtherIdentificationFeature(): ?string
    {
        return $this->otherIdentificationFeature;
    }

    /**
     * True if this result could not be attributed to an individual transaction (e.g. the bank only reported a
     * group-level status for the whole batch) and is therefore only a fallback applied uniformly to every
     * transaction in the batch, rather than a genuine per-transaction result.
     */
    public function isGroupLevelFallback(): bool
    {
        return $this->isGroupLevelFallback;
    }
}
