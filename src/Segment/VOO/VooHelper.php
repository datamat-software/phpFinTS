<?php

namespace Fhp\Segment\VOO;

use Fhp\Model\VopConfirmationRequestImpl;
use Fhp\Protocol\Message;
use Fhp\Protocol\UnexpectedResponseException;

/**
 * Creates request segments and interprets response segments for VoP Opt-Out (Namensabgleich Opt-Out).
 * @see FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01_2025_06_27_FV.pdf
 * Section: C.10.7.2
 */
// Generiert mit Claude Opus 4.8
class VooHelper
{
    /**
     * @return HKVOOv1 A segment that tells the bank to execute the payment order sent alongside it (in the same
     *     message) without a Verification of Payee check.
     */
    public static function createHKVOOForRequest(): HKVOOv1
    {
        return HKVOOv1::createEmpty();
    }

    /**
     * @param Message $response The response we just received from the server.
     * @param int $hkvooSegmentNumber The number of the HKVOO segment in the request we had sent.
     * @return ?VopConfirmationRequestImpl If the response contains a confirmation request for the user, it is
     *     returned, otherwise null (which may imply that the action was executed without requiring confirmation).
     *     Unlike the Opt-In case (see {@link \Fhp\Segment\VPP\VopHelper}), there is no verification result to report -
     *     the whole point of Opt-Out is that no Verification of Payee check was performed at all - so
     *     {@link VopConfirmationRequestImpl::getVerificationResult()} is null and
     *     {@link VopConfirmationRequestImpl::getTransactionResults()} is empty.
     */
    public static function checkVooConfirmationRequired(
        Message $response,
        int $hkvooSegmentNumber,
    ): ?VopConfirmationRequestImpl {
        /** @var ?HIVOOv1 $hivoo */
        $hivoo = $response->findSegment(HIVOOv1::class);
        if ($hivoo === null) {
            return null;
        }
        if ($hivoo->vopId === null) {
            throw new UnexpectedResponseException('Missing HIVOO.vopId even though VOP Opt-Out should be completed.');
        }

        return new VopConfirmationRequestImpl(
            $hivoo->vopId,
            null,
            $hivoo->aufklaerungstextOptOut,
            null,
            null,
        );
    }
}
