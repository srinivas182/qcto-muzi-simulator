<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A learner at a training provider. The ID number is encrypted; only its last 4 digits are shown by default.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string $first_names
 * @property string $surname
 * @property string|null $phone
 * @property string|null $email
 * @property string $id_type
 * @property string $id_number_encrypted
 * @property string $id_number_hash
 * @property string $id_last4
 * @property Carbon|null $date_of_birth
 * @property string|null $gender
 * @property string|null $citizenship
 * @property string|null $home_language
 * @property string|null $qcto_learner_number
 * @property string $status
 * @property string|null $created_by
 * @property Carbon|null $joined_at
 * @property string|null $title
 * @property string|null $middle_name
 * @property string|null $alt_id_type
 * @property string|null $equity_encrypted
 * @property array{status?: string, rating?: string|null}|null $disability_encrypted
 * @property string|null $nationality_code
 * @property string|null $citizen_status
 * @property string|null $immigrant_status
 * @property string|null $socioeconomic_code
 * @property array<string, string|null>|null $home_address
 * @property array<string, string|null>|null $postal_address
 * @property string|null $province_code
 * @property string|null $stats_area_code
 * @property Carbon|null $popi_agreed_at
 * @property string|null $flc_code
 * @property string|null $flc_sor_number
 * @property Carbon $updated_at
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class Learner extends Model
{
    use BelongsToTenant, HasUuids;

    public const INVITED = 'invited';

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    /** The 11 official languages of South Africa (plus "other"), as ISO 639 codes. */
    public const LANGUAGES = ['en', 'af', 'zu', 'xh', 'st', 'tn', 'nso', 'ts', 'ss', 've', 'nr', 'sasl', 'other'];

    protected $fillable = ['user_id', 'first_names', 'surname', 'phone', 'email', 'id_type', 'id_number_encrypted', 'id_number_hash', 'id_last4', 'date_of_birth', 'gender', 'citizenship', 'home_language', 'qcto_learner_number', 'status', 'created_by', 'joined_at',
        'title', 'middle_name', 'alt_id_type', 'equity_encrypted', 'disability_encrypted', 'nationality_code', 'citizen_status', 'immigrant_status',
        'socioeconomic_code', 'home_address', 'postal_address', 'province_code', 'stats_area_code', 'popi_agreed_at', 'flc_code', 'flc_sor_number'];

    protected $hidden = ['id_number_encrypted', 'id_number_hash', 'equity_encrypted', 'disability_encrypted'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date', 'joined_at' => 'datetime', 'popi_agreed_at' => 'datetime',
            'equity_encrypted' => 'encrypted', 'disability_encrypted' => 'encrypted:array',
            'home_address' => 'array', 'postal_address' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fullName(): string
    {
        return trim($this->first_names.' '.$this->surname);
    }
}
