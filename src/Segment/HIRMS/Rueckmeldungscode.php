<?php

namespace Fhp\Segment\HIRMS;

use Fhp\Protocol\DialogInitialization;

/**
 * Enum for the response codes that the server can send.
 *
 * @link https://www.hbci-zka.de/dokumente/spezifikation_deutsch/FinTS_Rueckmeldungscodes_2019-07-22_final_version.pdf
 * @link https://www.hbci-zka.de/dokumente/spezifikation_deutsch/fintsv3/FinTS_3.0_Security_Sicherheitsverfahren_PINTAN_2020-07-10_final_version.pdf
 */
abstract class Rueckmeldungscode
{
    /**
     * These response codes are soft warnings because the server allows unlocking the account through FinTS. But since
     * this library does not support the unlocking actions, we consider these warnings a hard failure, which aborts the
     * login. The user needs to unlock the account through the bank's web interface or customer support instead.
     */
    public const TREAT_WARNINGS_AS_ERRORS = [self::PIN_VORLAEUFIG_GESPERRT, self::ZUGANG_VORLAEUFIG_GESPERRT];

    /**
     * @param int $code A code received from the server.
     * @return bool Whether it is a success code (indicating that the action was executed normally).
     */
    public static function isSuccess(int $code): bool
    {
        return 0 < $code && $code <= 1000;
    }

    // NOTE: FinTS v4 additionally knows "Hinweise" (similar to INFO level in logging) that are 1000..1999.

    /**
     * @param int $code A code received from the server.
     * @return bool Whether it is a warning code (indicating that the action was executed, but there may have been a
     *     problem in doing so).
     */
    public static function isWarning(int $code): bool
    {
        return 3000 <= $code && $code < 4000;
    }

    /**
     * @param int $code A code received from the server.
     * @return bool Whether it is a warning code (indicating that the action was rejected).
     */
    // Bugfix (real-world find, 2026-07-26): war zuvor "9000 < $code", schloss den Code 9000 selbst aus. Ein
    // Live-Test gegen fints2.atruvia.de lieferte HIRMS 9000 ("Weitere Verarbeitung des Auftrags aufgrund
    // interner Probleme fehlgeschlagen") waehrend des VOP-Pollings - dieser (informativere) Text wurde dadurch
    // stillschweigend verworfen, uebrig blieb nur der generische globale 9050-Text in der Exception-Message.
    // Generiert mit Claude Opus 4.8
    public static function isError(int $code): bool
    {
        return (9000 <= $code && $code <= 9999) || in_array($code, self::TREAT_WARNINGS_AS_ERRORS);
    }

    /**
     * Umfang der Prüfung ist kreditinstitutsspezifisch. Mindestanforderung: physisch korrekt empfangen; Status ist
     * nicht rechtsverbindlich.
     */
    public const ENTGEGENGENOMMEN = 10;

    /**
     * Der Auftrag wurde ausgeführt.
     */
    public const AUSGEFUEHRT = 20;

    /**
     * Bestätigung der Dialogbeendigung des Benutzers oder des Kreditinstituts.
     */
    public const BEENDET = 100;

    /**
     * Nicht verfügbar.
     * zurzeit keine Börsenkurse abrufbar
     * Keine neuen Einträge im Statusprotokoll
     * Information wird zur Zeit nicht angeboten
     * Wertpapierdatei ist bereits aktuell
     */
    public const NICHT_VERFUEGBAR = 3010;

    /**
     * Es liegen weitere Informationen vor.
     * Tells the client that the response is incomplete and the request needs to be re-sent with the pagination token
     * ("Aufsetzpunkt") that is contained in the Rueckmeldung parameters.
     */
    public const AUFSETZPUNKT = 3040;

    /**
     * "SEPA-Instant Payment Statusabfrage HKIPS veranlassen".
     * Der Auftrag wurde angenommen, das endgültige interbankliche Ergebnis steht aber noch aus. Der Kunde kann im
     * Anschluss den Geschäftsvorfall "SEPA-Instant Payment Status" (HKIPS) durchführen.
     */
    // Generiert mit Claude Opus 4.8
    public const SEPA_INSTANT_STATUSABFRAGE_VERANLASSEN = 3045;

    /**
     * "Überprüfen Sie Ihre Umsätze."
     * Es wird kein weiterer Status geliefert, der Kunde muss den Ausgang selbst über die Umsätze kontrollieren.
     */
    // Generiert mit Claude Opus 4.8
    public const SEPA_INSTANT_UMSAETZE_PRUEFEN = 3046;

    /**
     * "Auftrag wird unter Referenz xxx verarbeitet".
     * Die Bearbeitungsreferenz (z.B. für Reklamationsfälle) steht in rueckmeldungsparameter[0].
     */
    // Generiert mit Claude Opus 4.8
    public const AUFTRAG_WIRD_UNTER_REFERENZ_VERARBEITET = 3070;

    /**
     * "Auftrag wird als Standard-SEPA-Überweisung bearbeitet".
     * Die Zahlung war nicht als Echtzeitüberweisung anbringbar (z.B. Empfängerbank nicht Instant-Payment-fähig oder
     * Limit nicht ausreichend) und wurde umgewandelt - nur möglich, wenn der Auftrag "Umwandlung nach
     * SEPA-Überweisung zulässig" gesetzt hatte. Für umgewandelte Aufträge liefert die Statusabfrage keine weiteren
     * Informationen zur Anbringung mehr.
     */
    // Generiert mit Claude Opus 4.8
    public const AUSGEFUEHRT_ALS_STANDARD_SEPA_UEBERWEISUNG = 3270;

    public const VOP_KEINE_NAMENSABWEICHUNG = 25;

    public const VOP_ERGEBNIS_NAMENSABGLEICH_PRUEFEN = 3090;

    public const VOP_AUSFUEHRUNGSAUFTRAG_NICHT_BENOETIGT = 3091;

    public const VOP_NAMENSABGLEICH_IST_NOCH_IN_BEARBEITUNG = 3093;

    public const VOP_NAMENSABGLEICH_IST_KOMPLETT = 3094;

    /**
     * Zugelassene Ein- und Zwei-Schritt-Verfahren für den Benutzer (+ Rückmeldungsparameter).
     * The parameters reference the VerfahrensparameterZweiSchrittVerfahren.sicherheitsfunktion values (900..997) from
     * HITANS, or 999 to indicate Ein-Schritt-Verfahren.
     */
    public const ZUGELASSENE_VERFAHREN = 3920;

    /**
     * PIN gesperrt. Entsperren mit GV "PIN-Sperre aufheben" möglich.
     * Note that this library does not support unlocking accounts through FinTS.
     */
    public const PIN_VORLAEUFIG_GESPERRT = 3931;

    /**
     * Ihr Zugang ist vorläufig gesperrt - bitte PIN-Sperre aufheben.
     * Es ist die Durchführung eines HKPSA erforderlich.
     * Note that this library does not support HKPSA.
     */
    public const ZUGANG_VORLAEUFIG_GESPERRT = 3938;

    /**
     * Der eingereichte HKTAN ist entwertet und der Auftrag (nach
     * vollständiger Übermittlung des Prüfergebnisses) soll erneut mit einem neuen
     * HKTAN in Verbindung mit einem HKVPA eingereicht werden, sofern der
     * Kunde die Ausführung weiterhin wünscht.
     */
    public const FREIGABE_KANN_NICHT_ERTEILT_WERDEN = 3945;

    /**
     * Starke Kundenauthentifizierung nicht notwendig.
     * The official code with which the bank announces up front that the order it just received does not require
     * strong customer authentication (e.g. a "Bagatellbetrag" below the institute's SCA threshold), upon which the
     * client stops sending HKTAN for it.
     * @see FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01_2025_06_27_FV.pdf, footnote to the VOP flow charts
     */
    // Generiert mit Claude Opus 4.8
    public const STARKE_KUNDENAUTHENTIFIZIERUNG_NICHT_NOTWENDIG = 3076;

    /**
     * Es wurde keine Challenge erzeugt.
     * Purely a statement about the message that was just answered, NOT about the order: Atruvia/GAD sends it together
     * with {@link FREIGABE_KANN_NICHT_ERTEILT_WERDEN} whenever a Verification of Payee still has to complete, and it
     * does so regardless of the amount (observed for 2,00 EUR and for 250,00 EUR alike). It therefore must not be
     * mistaken for {@link STARKE_KUNDENAUTHENTIFIZIERUNG_NICHT_NOTWENDIG}, which is the actual "no SCA needed" signal.
     */
    // Generiert mit Claude Opus 4.8
    public const KEINE_CHALLENGE_ERZEUGT = 3905;

    /**
     * Starke Kundenauthentifizierung noch ausstehend.
     * Indicates that the decoupled authentication is still outstanding.
     */
    public const STARKE_KUNDENAUTHENTIFIZIERUNG_NOCH_AUSSTEHEND = 3956;

    /**
     * In einer Nachricht ist mindestens ein fehlerhafter Auftrag enthalten.
     */
    public const TEILWEISE_FEHLERHAFT = 9050;

    /**
     * Neue Kundensystem-ID anfordern.
     * Als Antwort auf eine Dialoginitialisierungsnachricht ({@link DialogInitialization}).
     */
    public const NEUE_KUNDENSYSTEM_ID_HOLEN = 9391;

    /**
     * Kreditinstitutsseitige Beendigung des Dialoges
     */
    public const ABGEBROCHEN = 9800;

    /**
     * Ihre PIN ist gesperrt.
     */
    public const PIN_GESPERRT = 9930;

    /**
     * Sperrung des Kontos nach %1 Fehlversuchen
     * Teilnehmersperre durchgeführt
     * Teilnehmersperre durchgeführt, Entsperren nur durch Kreditinstitut
     */
    public const TEILNEHMER_GESPERRT = 9931;

    /**
     * Ihr Zugang ist gesperrt - Bitte informieren Sie Ihren Berater.
     * @link https://wiki.windata.de/index.php?title=HBCI-Fehlermeldungen
     */
    public const ZUGANG_GESPERRT = 9933;

    /**
     * TAN ungültig.
     * Signatur ungültig.
     */
    public const TAN_UNGUELTIG = 9941;

    /**
     * PIN ungültig.
     * Neue PIN ungültig.
     * Neue PIN zu kurz.
     * Neue PIN zu lang.
     */
    public const PIN_UNGUELTIG = 9942;

    /**
     *  TAN bereits verbraucht.
     */
    public const TAN_BEREITS_VERBRAUCHT = 9943;

    /**
     * Zeitüberschreitung im Zwei-Schritt-Verfahren
     * TAN/Signatur ungültig
     */
    public const ZEITUEBERSCHREITUNG_IM_ZWEI_SCHRITT_VERFAHREN = 9951;
}
