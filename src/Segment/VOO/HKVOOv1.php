<?php

namespace Fhp\Segment\VOO;

use Fhp\Segment\BaseSegment;

/**
 * Segment: Namensabgleich Opt-Out
 *
 * @see FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01_2025_06_27_FV.pdf
 * Section: C.10.7.2 a)
 */
// Generiert mit Claude Opus 4.8
class HKVOOv1 extends BaseSegment
{
    // No fields beyond the inherited Segmentkopf - this segment only signals to the bank that the payment order
    // sent alongside it (in the same message) should be executed without a Verification of Payee check.
}
