<?php

namespace Fhp\Segment\IPS;

use Fhp\Segment\BaseDeg;

/**
 * DEG: Unterstützte SEPA pain messages
 *
 * Field 3 of {@link HKIPSv1}: the pain schemas the client is able to accept in the {@link HIIPSv1} response. Modelled
 * after {@link \Fhp\Segment\CAZ\UnterstuetzteCamtMessages}, which is the same construct for camt.
 *
 * @link https://www.hbci-zka.de/dokumente/spezifikation_deutsch/fintsv3/FinTS_3.0_Messages_Geschaeftsvorfaelle_2022-04-15_final_version.pdf
 * Section: C.10.2.9.2 a)
 */
// Generiert mit Claude Opus 4.8
class UnterstuetzteSEPAPainMessages extends BaseDeg
{
    /** @var string[] @Max(99) Max length each: 256 */
    public array $painDescriptor;

    /** @param string[] $painDescriptor */
    public static function create(array $painDescriptor): UnterstuetzteSEPAPainMessages
    {
        $result = new UnterstuetzteSEPAPainMessages();
        $result->painDescriptor = array_values($painDescriptor);
        return $result;
    }
}
