<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\Auditable;
use App\Notifications\OrderNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
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

    /**
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Where this user's emails go. Customer order emails use the address given
     * at checkout (see OrderNotification::mailRecipient); everything else uses
     * the account email.
     */
    public function routeNotificationForMail(Notification $notification): string
    {
        return $notification instanceof OrderNotification
            ? $notification->mailRecipient($this)
            : $this->email;
    }

    /**
     * Staff: admins and super admins both run the shop.
     */
    public function isAdmin(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::SuperAdmin], true);
    }

    /**
     * The owner role: everything an admin can do, plus the system area (logs,
     * audit trail, user roles).
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    /**
     * Whether staff alert emails (new orders, low stock) go to this user. Super
     * admins still get the in-app bell entries but no email.
     */
    public function receivesStaffEmails(): bool
    {
        return $this->isAdmin() && ! $this->isSuperAdmin();
    }

    /**
     * @return list<string>
     */
    public static function auditedEvents(): array
    {
        return ['updated', 'deleted'];
    }
}
