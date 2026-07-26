<?php

namespace Fhp\Model;

use Fhp\Syntax\Bin;

/** Application code should not interact directly with this type, see {@link VopConfirmationRequest instead}. */
class VopConfirmationRequestImpl implements VopConfirmationRequest
{
    private Bin $vopId;
    private ?\DateTime $expiration;
    private ?string $informationForUser;
    private ?string $verificationResult;
    private ?string $verificationNotApplicableReason;
    private ?string $deviatingPayeeName;
    private ?string $payeeIban;
    private ?string $payeeIbanAdditionalInformation;
    private ?string $otherIdentificationFeature;
    /** @var VopTransactionResult[] */
    private array $transactionResults;
    /** @var int[] */
    private array $transactionStatusCounts;
    /** @var string[] */
    // Generiert mit Claude Opus 4.8
    private array $groupInformationTexts;

    // Generiert mit Claude Opus 4.8
    public function __construct(
        Bin $vopId,
        ?\DateTime $expiration,
        ?string $informationForUser,
        ?string $verificationResult,
        ?string $verificationNotApplicableReason,
        ?string $deviatingPayeeName = null,
        ?string $payeeIban = null,
        ?string $payeeIbanAdditionalInformation = null,
        ?string $otherIdentificationFeature = null,
        array $transactionResults = [],
        array $transactionStatusCounts = [],
        array $groupInformationTexts = [],
    ) {
        $this->vopId = $vopId;
        $this->expiration = $expiration;
        $this->informationForUser = $informationForUser;
        $this->verificationResult = $verificationResult;
        $this->verificationNotApplicableReason = $verificationNotApplicableReason;
        $this->deviatingPayeeName = $deviatingPayeeName;
        $this->payeeIban = $payeeIban;
        $this->payeeIbanAdditionalInformation = $payeeIbanAdditionalInformation;
        $this->otherIdentificationFeature = $otherIdentificationFeature;
        $this->transactionResults = $transactionResults;
        $this->transactionStatusCounts = $transactionStatusCounts;
        $this->groupInformationTexts = $groupInformationTexts;
    }

    public function getVopId(): Bin
    {
        return $this->vopId;
    }

    public function getExpiration(): ?\DateTime
    {
        return $this->expiration;
    }

    public function getInformationForUser(): ?string
    {
        return $this->informationForUser;
    }

    public function getVerificationResult(): ?string
    {
        return $this->verificationResult;
    }

    public function getVerificationNotApplicableReason(): ?string
    {
        return $this->verificationNotApplicableReason;
    }

    // Generiert mit Claude Opus 4.8
    public function getDeviatingPayeeName(): ?string
    {
        return $this->deviatingPayeeName;
    }

    // Generiert mit Claude Opus 4.8
    public function getPayeeIban(): ?string
    {
        return $this->payeeIban;
    }

    // Generiert mit Claude Opus 4.8
    public function getPayeeIbanAdditionalInformation(): ?string
    {
        return $this->payeeIbanAdditionalInformation;
    }

    // Generiert mit Claude Opus 4.8
    public function getOtherIdentificationFeature(): ?string
    {
        return $this->otherIdentificationFeature;
    }

    // Generiert mit Claude Opus 4.8
    public function getTransactionResults(): array
    {
        return $this->transactionResults;
    }

    // Generiert mit Claude Opus 4.8
    public function getTransactionStatusCounts(): array
    {
        return $this->transactionStatusCounts;
    }

    // Generiert mit Claude Opus 4.8
    public function getGroupInformationTexts(): array
    {
        return $this->groupInformationTexts;
    }
}
