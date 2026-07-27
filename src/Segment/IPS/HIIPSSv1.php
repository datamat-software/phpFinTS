<?php
/** @noinspection PhpUnused */

namespace Fhp\Segment\IPS;

use Fhp\Segment\BaseGeschaeftsvorfallparameter;

/**
 * Segment: SEPA-Instant Payment Status Parameter (Version 1)
 *
 * @link https://www.hbci-zka.de/dokumente/spezifikation_deutsch/fintsv3/FinTS_3.0_Messages_Geschaeftsvorfaelle_2022-04-15_final_version.pdf
 * Section: C.10.2.9.2 c)
 */
// Generiert mit Claude Opus 4.8
class HIIPSSv1 extends BaseGeschaeftsvorfallparameter
{
    public ParameterSepaInstantPaymentStatusV1 $parameter;

    // Generiert mit Claude Opus 4.8
    public function getParameter(): ParameterSepaInstantPaymentStatusV1
    {
        return $this->parameter;
    }
}
