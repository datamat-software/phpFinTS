<?php
/** @noinspection PhpUnused */

namespace Fhp\Segment\IPS;

use Fhp\Segment\BaseDeg;
use Fhp\Segment\UnterstuetzteSEPADatenformate;
use Fhp\Segment\UnterstuetzteSEPADatenformateTrait;

/**
 * Data Element Group: Parameter SEPA-Instant Payment Status (Version 1)
 *
 * @link https://www.hbci-zka.de/dokumente/spezifikation_deutsch/fintsv3/FinTS_3.0_Messages_Geschaeftsvorfaelle_2022-04-15_final_version.pdf
 * Section: D (letter P), "Parameter SEPA-Instant Payment Status"
 */
// Generiert mit Claude Opus 4.8
class ParameterSepaInstantPaymentStatusV1 extends BaseDeg implements UnterstuetzteSEPADatenformate
{
    use UnterstuetzteSEPADatenformateTrait;

    /**
     * How long the client has to wait after submitting the instant payment before it may query its status. The
     * specification declares this as a two-digit number without naming a unit; seconds is the only reading that
     * matches the SCT Inst execution window this transaction exists for.
     */
    public int $mindestwartezeit;
    /** @var string[]|null @Max(9) Max length each: 256 */
    public ?array $unterstuetzteSepaDatenformate = null;

    // Generiert mit Claude Opus 4.8
    public function getMindestwartezeit(): int
    {
        return $this->mindestwartezeit;
    }
}
