<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'google_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Whether this email is in the ADMIN_EMAILS config list - these accounts
     * are always Admin, permanently: GoogleAuthController seeds the role
     * from this on first creation, and UserAccessController refuses to let
     * any other admin change it afterward (see PLANNING_LOG.md).
     */
    public static function isEnvAdmin(string $email): bool
    {
        return in_array(strtolower($email), array_map('strtolower', config('services.google.admin_emails')), true);
    }

    /** Whether this email's domain matches ALLOWED_GOOGLE_DOMAIN. */
    public static function isAllowedDomainEmail(?string $email): bool
    {
        $allowedDomain = config('services.google.allowed_domain');
        if (blank($email) || blank($allowedDomain)) {
            return false;
        }

        $domain = substr((string) strrchr($email, '@'), 1);

        return strcasecmp($domain, $allowedDomain) === 0;
    }
}
