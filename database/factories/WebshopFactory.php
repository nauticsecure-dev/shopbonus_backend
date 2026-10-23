<?php

namespace Database\Factories;

use App\Enums\EcommercePlatform;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\WebshopStatus;
use App\Models\User;
use App\Models\Webshop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Webshop>
 */
class WebshopFactory extends Factory
{
    protected $model = Webshop::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'platform' => fake()->randomElement(EcommercePlatform::cases())->value,
            'status' => WebshopStatus::Draft,
            'created_by' => User::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => WebshopStatus::Approved, 'approved_at' => now()]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => WebshopStatus::Suspended, 'suspended_at' => now()]);
    }

    /**
     * @param  list<string>  $extraPermissions
     */
    public function withMember(User $user, MembershipRole $role = MembershipRole::Owner, array $extraPermissions = [], MembershipStatus $status = MembershipStatus::Active): static
    {
        return $this->afterCreating(function (Webshop $webshop) use ($user, $role, $extraPermissions, $status) {
            $webshop->memberships()->create([
                'user_id' => $user->id,
                'role' => $role,
                'permissions' => $extraPermissions ?: null,
                'status' => $status,
                'joined_at' => now(),
            ]);
        });
    }
}
