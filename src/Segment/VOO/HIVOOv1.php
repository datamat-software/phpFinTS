<?php

namespace Fhp\Segment\VOO;

use Fhp\Segment\BaseSegment;
use Fhp\Syntax\Bin;

/**
 * Segment: Namensabgleich Opt-Out rückmelden
 *
 * @see FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01_2025_06_27_FV.pdf
 * Section: C.10.7.2 b)
 */
// Generiert mit Claude Opus 4.8
class HIVOOv1 extends BaseSegment
{
    public ?Bin $vopId = null;

    public ?string $aufklaerungstextOptOut = null;
}
