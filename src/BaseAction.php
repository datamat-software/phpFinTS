<?php

/** @noinspection PhpUnused */

namespace Fhp;

use Fhp\Model\PollingInfo;
use Fhp\Model\TanRequest;
use Fhp\Model\VopConfirmationRequest;
use Fhp\Model\VopReportAccumulator;
use Fhp\Protocol\ActionIncompleteException;
use Fhp\Protocol\ActionPendingException;
use Fhp\Protocol\BPD;
use Fhp\Protocol\Message;
use Fhp\Protocol\TanRequiredException;
use Fhp\Protocol\UnexpectedResponseException;
use Fhp\Protocol\UPD;
use Fhp\Protocol\VopConfirmationRequiredException;
use Fhp\Segment\BaseSegment;
use Fhp\Segment\HIRMS\Rueckmeldung;
use Fhp\Segment\HIRMS\Rueckmeldungscode;

/**
 * Base class for actions that can be performed against a bank server. On a high level, there are two kinds of actions:
 *  - requests for information (e.g. an account statement), which the bank server will return
 *  - transactions (e.g. a wire transfer to another account), which the bank server will execute.
 *
 * In part, this class is designed like futures/promises in concurrent programming. The outcome of the action (i.e. the
 * requested information or the execution confirmation of the transaction) becomes available in the future, possibly
 * much later than when the request was sent, in case the user needs to enter a TAN.
 * All action instances are serializable, so that the execution can be interrupted to ask the user for a TAN. Once the
 * TAN is available, the execution can resume either a couple seconds later in the same PHP process using the same
 * physical connection to the bank, or on the order of minutes later in a new PHP process with a newly established
 * connection to the bank. Note that the serialization only applies to selected relevant request parameters, and not to
 * the response. Thus it is only possible to serialize an action when its execution has been attempted but resulted in a
 * TAN request.
 * Actions that do not require a TAN will complete immediately.
 *
 * The implementation of an action consists of two parts: assembling the request to the bank, and processing the
 * response.
 */
abstract class BaseAction implements \Serializable
{
    /** @var int[] Stores segment numbers that were assigned to the segments returned from {@link createRequest()}. */
    protected ?array $requestSegmentNumbers = null;

    /**
     * Contains the name of the segment, that might need a tan, used by FinTs::execute to signal
     * to the bank that supplying a tan is supported.
     */
    protected ?string $needTanForSegment = null;

    /** If set, the last response from the server regarding this action asked for a TAN from the user. */
    protected ?TanRequest $tanRequest = null;

    /** If set, this action is currently waiting for a long-running operation on the server to complete. */
    protected ?PollingInfo $pollingInfo = null;

    /** If set, this action needs the user's confirmation to be completed. */
    protected ?VopConfirmationRequest $vopConfirmationRequest = null;

    /**
     * If set, the user has already confirmed the Verification of Payee result, but the corresponding HKVPA has not
     * been sent to the bank yet because it has to travel together with the answer to a TAN challenge that the bank
     * had already issued in the same response as the VOP result (see
     * FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01_2025_06_27_FV.pdf, Abbildung 3: "HKVPA (mit VOP-ID aus HIVPP)
     * + HKTAN, Freigabe des Auftrags", without re-submitting the payment order).
     */
    // Generiert mit Claude Opus 4.8
    protected ?VopConfirmationRequest $confirmedVopConfirmationRequest = null;

    /**
     * Collects the (possibly chunked and/or stepwise delivered) pain.002 payment status report of a Verification of
     * Payee check across all HKVPP/HIVPP round trips of this action.
     */
    // Generiert mit Claude Opus 4.8
    protected ?VopReportAccumulator $vopReportAccumulator = null;

    /**
     * Set once the bank has answered one of this action's requests with Rueckmeldungscode 3905 "Es wurde keine
     * Challenge erzeugt", i.e. it decided that this particular order does not require strong customer authentication
     * (typically an amount below the institute's SCA threshold). It stays set for the rest of the action, because the
     * decision is about the order, not about the individual message, and it has to survive the serialization between
     * the Verification of Payee round trips. See {@link FinTs::confirmVop()}, which must then not ask for a challenge
     * again.
     */
    // Generiert mit Claude Opus 4.8
    protected bool $bankCreatedNoChallenge = false;

    /**
     * If true, {@link FinTs::execute()} sends an HKVOO (VoP Opt-Out) segment alongside this action's request instead
     * of an HKVPP (Verification of Payee), provided the bank's BPD allows Opt-Out for this action's request segment
     * (see {@link \Fhp\Protocol\BPD::vopOptOutAllowedForRequest()}). Only meaningful for actions that submit a batch
     * payment order (e.g. {@link \Fhp\Action\SendSEPATransfer}, {@link \Fhp\Action\SendSEPADirectDebit}) - the bank
     * rejects Opt-Out for orders with only a single transaction.
     * @see \Fhp\Segment\VOO\VooHelper
     */
    // Generiert mit Claude Opus 4.8
    protected bool $vopOptOutRequested = false;

    protected bool $isDone = false;

    /**
     * Will be populated with the message the bank sent along with the success indication, can be used to show to
     * the user.
     */
    public ?string $successMessage = null;

    /**
     * All Rueckmeldungen the bank sent in response to this action's request segments, regardless of their code.
     * {@link $successMessage} only ever carries the text of code 10/20, so anything else the bank said (e.g. a
     * processing reference, or the notice that an instant payment was converted to a regular transfer) used to be
     * discarded. Callers can surface these verbatim.
     *
     * Deliberately NOT part of {@link __serialize()}: this is only ever populated in {@link processResponse()}, which
     * sets isDone=true as its first statement, and __serialize() refuses to run on a completed action. Adding a ninth
     * tuple element would also silently shift previously serialized actions, because __unserialize() pads to 8.
     * @var Rueckmeldung[]
     */
    // Generiert mit Claude Opus 4.8
    protected array $bankAnswers = [];

    /**
     * @deprecated Beginning from PHP7.4 __unserialize is used for new generated strings, then this method is only used for previously generated strings - remove after May 2023
     *
     * NOTE: A common mistake is to call this function directly. Instead, you probably want `serialize($instance)`.
     *
     * An action can only be serialized before it was completed.
     * If a sub-class overrides this, it should call the parent function and include it in its result.
     * @return string The serialized action, e.g. for storage in a database. This will not contain sensitive user data.
     */
    public function serialize(): string
    {
        return serialize($this->__serialize());
    }

    /**
     * An action can only be serialized before it was completed.
     * If a sub-class overrides this, it should call the parent function and include it in its result.
     *
     * @return array The serialized action, e.g. for storage in a database. This will not contain sensitive user data.
     *   Note that this is not necessarily valid UTF-8, so you should store it as a BLOB column or raw bytes.
     */
    public function __serialize(): array
    {
        if ($this->isDone()) {
            throw new \RuntimeException('Completed actions cannot be serialized.');
        }
        return [
            $this->requestSegmentNumbers,
            $this->tanRequest,
            $this->needTanForSegment,
            $this->pollingInfo,
            $this->vopConfirmationRequest,
            $this->vopOptOutRequested,
            $this->confirmedVopConfirmationRequest,
            $this->vopReportAccumulator,
            $this->bankCreatedNoChallenge,
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
            $this->requestSegmentNumbers,
            $this->tanRequest,
            $this->needTanForSegment,
            $this->pollingInfo,
            $this->vopConfirmationRequest,
            $this->vopOptOutRequested,
            $this->confirmedVopConfirmationRequest,
            $this->vopReportAccumulator,
            $this->bankCreatedNoChallenge,
        ) = array_pad($serialized, 9, null);
        $this->vopOptOutRequested ??= false;
        // Generiert mit Claude Opus 4.8
        $this->bankCreatedNoChallenge ??= false;
    }

    /**
     * @return bool Whether the underlying operation has completed successfully and the result in this "future" is
     *     available. Note: If this returns false, check {@link needsTan()}.
     */
    public function isDone(): bool
    {
        return $this->isDone;
    }

    /**
     * @return bool If this returns true, the underlying operation has not completed because it is awaiting a TAN or a
     *     "decoupled" confirmation. You should ask the user for this TAN/confirmation and pass it to
     *     {@link FinTs::submitTan()} or call {@link FinTs::checkDecoupledSubmission()}, respectively.
     */
    public function needsTan(): bool
    {
        return !$this->isDone() && $this->tanRequest !== null;
    }

    public function getNeedTanForSegment(): ?string
    {
        return $this->needTanForSegment;
    }

    public function getTanRequest(): ?TanRequest
    {
        return $this->tanRequest;
    }

    public function needsPollingWait(): bool
    {
        return !$this->isDone() && $this->pollingInfo !== null;
    }

    public function getPollingInfo(): ?PollingInfo
    {
        return $this->pollingInfo;
    }

    public function needsVopConfirmation(): bool
    {
        return !$this->isDone() && $this->vopConfirmationRequest !== null;
    }

    public function getVopConfirmationRequest(): ?VopConfirmationRequest
    {
        return $this->vopConfirmationRequest;
    }

    /**
     * Requests that {@link FinTs::execute()} submit this action with VoP Opt-Out (HKVOO) instead of a Verification
     * of Payee check (HKVPP), if the bank's BPD allows Opt-Out for this action's request. Only meaningful for batch
     * payment orders with more than one transaction - the bank rejects Opt-Out otherwise.
     * @param bool $optOut Whether to request Opt-Out. Defaults to true.
     * @return $this
     */
    // Generiert mit Claude Opus 4.8
    public function requestVopOptOut(bool $optOut = true): static
    {
        $this->vopOptOutRequested = $optOut;
        return $this;
    }

    // Generiert mit Claude Opus 4.8
    public function isVopOptOutRequested(): bool
    {
        return $this->vopOptOutRequested;
    }

    /**
     * @return bool Whether the bank reported Rueckmeldungscode 3905 "Es wurde keine Challenge erzeugt" for this
     *     action, i.e. it will not authenticate this order with a TAN at all, see {@link $bankCreatedNoChallenge}.
     */
    // Generiert mit Claude Opus 4.8
    public function hasBankCreatedNoChallenge(): bool
    {
        return $this->bankCreatedNoChallenge;
    }

    /** To be called only by the FinTs instance that executes this action. */
    // Generiert mit Claude Opus 4.8
    final public function setBankCreatedNoChallenge(bool $bankCreatedNoChallenge): void
    {
        $this->bankCreatedNoChallenge = $bankCreatedNoChallenge;
    }

    // Generiert mit Claude Opus 4.8
    public function getVopReportAccumulator(): ?VopReportAccumulator
    {
        return $this->vopReportAccumulator;
    }

    /** To be called only by the FinTs instance that executes this action. */
    // Generiert mit Claude Opus 4.8
    final public function requireVopReportAccumulator(): VopReportAccumulator
    {
        return $this->vopReportAccumulator ??= new VopReportAccumulator();
    }

    /** To be called only by the FinTs instance that executes this action. */
    // Generiert mit Claude Opus 4.8
    final public function setVopReportAccumulator(?VopReportAccumulator $vopReportAccumulator): void
    {
        $this->vopReportAccumulator = $vopReportAccumulator;
    }

    // Generiert mit Claude Opus 4.8
    public function getConfirmedVopConfirmationRequest(): ?VopConfirmationRequest
    {
        return $this->confirmedVopConfirmationRequest;
    }

    /** To be called only by the FinTs instance that executes this action. */
    // Generiert mit Claude Opus 4.8
    final public function setConfirmedVopConfirmationRequest(?VopConfirmationRequest $confirmedVopConfirmationRequest): void
    {
        $this->confirmedVopConfirmationRequest = $confirmedVopConfirmationRequest;
    }

    /**
     * Throws an exception unless this action has been successfully executed, i.e. in the following cases:
     *  - the action has not been {@link FinTs::execute()}-d at all or the {@link FinTs::execute()} call for it threw an
     *    exception,
     *  - the action is awaiting a TAN/confirmation (as per {@link BaseAction::needsTan()},
     *  - the action is pending a long-running operation on the bank server ({@link BaseAction::needsPollingWait()}),
     *  - the action is awaiting the user's confirmation of the Verification of Payee result (as per
     *    {@link BaseAction::needsVopConfirmation()}).
     *
     * After executing an action, you can use this function to make sure that it succeeded. This is especially useful
     * for actions that don't have any results (as each result getter would call {@link ensureDone()} internally).
     * On the other hand, you do not need to call this function if you make sure that (1) you called
     * {@link FinTs::execute()} and (2) you checked and resolved all other special outcome states documented there.
     * Note that both exception types thrown from this method are sub-classes of {@link \RuntimeException}, so you
     * shouldn't need a try-catch block at the call site for this.
     * @throws ActionIncompleteException If the action hasn't even been executed.
     * @throws ActionPendingException If the action is pending a long-running server operation that needs polling.
     * @throws VopConfirmationRequiredException If the action requires the user's confirmation for VOP.
     * @throws TanRequiredException If the action needs a TAN.
     */
    public function ensureDone(): void
    {
        if ($this->tanRequest !== null) {
            throw new TanRequiredException($this->tanRequest);
        } elseif ($this->pollingInfo !== null) {
            throw new ActionPendingException($this->pollingInfo);
        } elseif ($this->vopConfirmationRequest !== null) {
            throw new VopConfirmationRequiredException($this->vopConfirmationRequest);
        } elseif (!$this->isDone()) {
            throw new ActionIncompleteException();
        }
    }

    /**
     * Called when this action is about to be executed, in order to construct the request.
     * @param BPD $bpd See {@link BPD}.
     * @param UPD|null $upd See {@link UPD}. This is usually present (non-null), except for a few special login and TAN
     *     management actions.
     * @return BaseSegment|BaseSegment[] A segment or a series of segments that should be sent to the bank server.
     *     Note that an action can return an empty array to indicate that it does not need to make a request to the
     *     server, but can instead compute the result just from the BPD/UPD, in which case it should set
     *     `$this->isDone = true;` already in {@link createRequest()} and {@link processResponse()} will never
     *     be executed.
     * @throws \InvalidArgumentException When the request cannot be built because the input data or BPD/UPD is invalid.
     */
    abstract protected function createRequest(BPD $bpd, ?UPD $upd);

    /**
     * Called by FinTs::execute when this action is about to be executed, in order to get a request. This function can
     * be called multiple times in case the response is paginated.
     * This method also tries to check if the segments might need a tan and stores this information for use in
     * FinTs::execute
     * @param BPD|null $bpd See {@link BPD}.
     * @param UPD|null $upd See {@link UPD}. This is usually present (non-null), except for a few special login and TAN
     *     management actions.
     * @return BaseSegment[] A segment or a series of segments that should be sent to the bank server.
     *      An empty array means that no request is necessary at all.
     * @throws \InvalidArgumentException When the request cannot be built because the input data or BPD/UPD is invalid.
     */
    public function getNextRequest(BPD $bpd, ?UPD $upd)
    {
        $requestSegments = $this->createRequest($bpd, $upd);
        $requestSegments = is_array($requestSegments) ? $requestSegments : [$requestSegments];

        $this->needTanForSegment = $bpd->tanRequiredForRequest($requestSegments);

        return $requestSegments;
    }

    /**
     * Called when this action was executed on the server (never if {@link createRequest()} returned an empty request),
     * to process the response. This function can be called multiple times in case the response is paginated.
     * In case the response indicates that this action failed, this function may throw an appropriate exception. Sub-classes should override this function
     * and call the parent/super function.
     * @param Message $response A fake message that contains the subset of segments received from the server that
     *     were in response to the request segments that were created by {@link createRequest()}.
     * @throws UnexpectedResponseException When the response indicates failure.
     */
    public function processResponse(Message $response)
    {
        $this->isDone = true;

        // Append rather than assign: for a PaginateableAction this method runs once per page.
        // Generiert mit Claude Opus 4.8
        $this->bankAnswers = array_merge($this->bankAnswers, $response->getAllRueckmeldungen());

        $info = $response->findRueckmeldungen(Rueckmeldungscode::AUSGEFUEHRT);
        if (count($info) === 0) {
            $info = $response->findRueckmeldungen(Rueckmeldungscode::ENTGEGENGENOMMEN);
        }
        if (count($info) > 0) {
            $this->successMessage = implode("\n", array_map(function (Rueckmeldung $rueckmeldung) {
                return $rueckmeldung->rueckmeldungstext;
            }, $info));
        }
    }

    /**
     * @return Rueckmeldung[] Everything the bank said about this action, empty until the action completed.
     * @see $bankAnswers
     */
    // Generiert mit Claude Opus 4.8
    public function getBankAnswers(): array
    {
        return $this->bankAnswers;
    }

    /** @return int[] */
    public function getRequestSegmentNumbers(): array
    {
        return $this->requestSegmentNumbers ?? [];
    }

    /**
     * To be called only by the FinTs instance that executes this action.
     * @param int[] $requestSegmentNumbers
     */
    final public function setRequestSegmentNumbers(array $requestSegmentNumbers)
    {
        foreach ($requestSegmentNumbers as $segmentNumber) {
            if (!is_int($segmentNumber)) {
                throw new \InvalidArgumentException("Invalid segment number: $segmentNumber");
            }
        }
        $this->requestSegmentNumbers = $requestSegmentNumbers;
    }

    /** To be called only by the FinTs instance that executes this action. */
    final public function setTanRequest(?TanRequest $tanRequest): void
    {
        $this->tanRequest = $tanRequest;
    }

    /** To be called only by the FinTs instance that executes this action. */
    final public function setPollingInfo(?PollingInfo $pollingInfo): void
    {
        $this->pollingInfo = $pollingInfo;
    }

    /** To be called only by the FinTs instance that executes this action. */
    final public function setVopConfirmationRequest(?VopConfirmationRequest $vopConfirmationRequest): void
    {
        $this->vopConfirmationRequest = $vopConfirmationRequest;
    }
}
