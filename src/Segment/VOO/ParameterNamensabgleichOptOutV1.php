<?php

namespace Fhp\Segment\VOO;

use Fhp\Segment\BaseDeg;

/**
 * DEG: Parameter Namensabgleich Opt-Out
 *
 * @see FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01_2025_06_27_FV.pdf
 *   Section: D
 */
// Generiert mit Claude Opus 4.8
class ParameterNamensabgleichOptOutV1 extends BaseDeg
{
    public bool $aufklaerungstextStrukturiert;

    /** @var string[] Segmentkennungen (e.g. "HKCCM"), for which the bank allows VoP Opt-Out. @Unlimited Max length each: 6 */
    public array $optOutZahlungsverkehrsauftrag;
}
