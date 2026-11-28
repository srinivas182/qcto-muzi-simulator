<?php

declare(strict_types=1);

namespace App\Qcto;

/**
 * Code lists from QCTO's data loading specification for the Learner Enrolment and Readiness for EISA file.
 * Labels here are for code comments and the API; the web app shows translated labels.
 */
final class QctoCodes
{
    public const TITLES = ['Mr', 'Mrs', 'Ms', 'Miss', 'Dr', 'Prof'];

    /** Column E. 533 = none (the learner has an SA ID). */
    public const ALT_ID_TYPES = ['527' => 'Passport / foreign ID', '565' => 'Refugee number', '538' => 'Work permit number', '540' => 'Birth certificate number'];

    /** Column F. Special personal information. */
    public const EQUITY = ['BA' => 'Black African', 'BC' => 'Coloured', 'BI' => 'Indian / Asian', 'Wh' => 'White', 'Oth' => 'Other', 'U' => 'Prefer not to say'];

    /** Column G. */
    public const NATIONALITY = ['SA' => 'South Africa', 'SDC' => 'SADC country', 'NAM' => 'Namibia', 'BOT' => 'Botswana', 'ZIM' => 'Zimbabwe', 'ANG' => 'Angola', 'MOZ' => 'Mozambique', 'LES' => 'Lesotho', 'SWA' => 'Eswatini', 'MAL' => 'Malawi', 'ZAM' => 'Zambia', 'MAU' => 'Mauritius', 'TAN' => 'Tanzania', 'SEY' => 'Seychelles', 'ZAI' => 'DR Congo', 'ROA' => 'Rest of Africa', 'EUR' => 'Europe', 'AIS' => 'Asia', 'NOR' => 'North America', 'SOU' => 'South or Central America', 'AUS' => 'Australia or Oceania', 'OOC' => 'Other country', 'U' => 'Unknown'];

    /** Column H, from our language codes. */
    public const LANGUAGE = ['en' => 'Eng', 'af' => 'Afr', 'zu' => 'Zul', 'xh' => 'Xho', 'st' => 'Ses', 'tn' => 'Set', 'nso' => 'Sep', 'ts' => 'Xit', 'ss' => 'Swa', 've' => 'Tsh', 'nr' => 'Nde', 'sasl' => 'SASL', 'other' => 'Oth'];

    /** Column J. */
    public const CITIZEN = ['SA' => 'SA citizen', 'PR' => 'Permanent resident', 'D' => 'Dual citizenship', 'O' => 'Other', 'U' => 'Unknown'];

    /** Column K. */
    public const SOCIOECONOMIC = ['01' => 'Employed', '02' => 'Unemployed, looking for work', '03' => 'Not working, not looking', '04' => 'Home-maker', '06' => 'Scholar or student', '07' => 'Pensioner or retired', '08' => 'Not working, disabled', '09' => 'Not working, no wish to work', '10' => 'Not working, other reason', 'U' => 'Unknown'];

    /** Column L. Special personal information. */
    public const DISABILITY = ['N' => 'None', '01' => 'Sight', '02' => 'Hearing', '03' => 'Communication', '04' => 'Physical', '05' => 'Intellectual', '06' => 'Emotional', '07' => 'Multiple', '09' => 'Disabled, not specified'];

    /** Column M, only when there is a disability. */
    public const DISABILITY_RATING = ['01' => 'No difficulty', '02' => 'Some difficulty', '03' => 'A lot of difficulty', '04' => 'Cannot do at all', '06' => 'Cannot yet be determined'];

    /** Column N. */
    public const IMMIGRANT = ['03' => 'SA citizen', '01' => 'Immigrant', '02' => 'Refugee'];

    /** Column AF. */
    public const PROVINCE = ['1' => 'Western Cape', '2' => 'Eastern Cape', '3' => 'Northern Cape', '4' => 'Free State', '5' => 'KwaZulu-Natal', '6' => 'North West', '7' => 'Gauteng', '8' => 'Mpumalanga', '9' => 'Limpopo', 'X' => 'Outside South Africa'];

    /** Column AN. */
    public const READINESS = ['1' => 'Enrolled', '2' => 'RPL for access to EISA (SDP)', '3' => 'Mixed mode to EISA', '4' => 'SDP training and assessment', '5' => 'SDP e-learning training and assessment', '6' => 'RPL for access to EISA (assessment or quality partner)'];

    /** Column AO. */
    public const FLC = ['01' => 'FLC competent', '02' => 'RPL', '03' => 'Grade 12 / NCV 4 maths and English pass', '04' => 'Not yet competent', '05' => 'FLC not completed yet', '06' => 'Not applicable (NQF 5 and above)', '07' => 'Enrolled for FLC', '08' => 'N3 maths and business language'];

    /**
     * Every list, for the web form.
     *
     * @return array<string, list<string>>
     */
    public static function all(): array
    {
        $k = fn (array $list): array => array_map('strval', array_keys($list));

        return [
            'titles' => self::TITLES, 'alt_id_types' => $k(self::ALT_ID_TYPES), 'equity' => $k(self::EQUITY), 'nationality' => $k(self::NATIONALITY),
            'citizen' => $k(self::CITIZEN), 'socioeconomic' => $k(self::SOCIOECONOMIC), 'disability' => $k(self::DISABILITY),
            'disability_rating' => $k(self::DISABILITY_RATING), 'immigrant' => $k(self::IMMIGRANT), 'province' => $k(self::PROVINCE),
            'readiness' => $k(self::READINESS), 'flc' => $k(self::FLC),
        ];
    }
}
