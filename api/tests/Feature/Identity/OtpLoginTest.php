<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

final class OtpLoginTest extends IdentityTestCase
{
    public function test_learner_signs_in_with_mobile_number_and_code(): void
    {
        $this->userIn($this->ubuntu, ['phone' => '+27821234567', 'name' => 'Thandiwe Mokoena']);

        $this->postJson($this->url('/auth/otp/request'), ['phone' => '082 123 4567'])
            ->assertStatus(202)
            ->assertJsonPath('sent_to', '+27 •• ••• 4567');

        $this->postJson($this->url('/auth/otp/verify'), [
            'phone' => '0821234567', 'code' => $this->lastCode('+27821234567'), 'client' => 'mobile', 'device_name' => 'Galaxy A15',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.name', 'Thandiwe Mokoena')
            ->assertJsonPath('user.consent_required', ['terms', 'popia_processing']);
    }

    public function test_unknown_numbers_get_the_same_answer_and_no_code(): void
    {
        $this->postJson($this->url('/auth/otp/request'), ['phone' => '0829999999'])->assertStatus(202);

        $this->assertArrayNotHasKey('+27829999999', $this->sentCodes);
    }

    public function test_invalid_number_is_rejected(): void
    {
        $this->postJson($this->url('/auth/otp/request'), ['phone' => '12'])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_wrong_code_is_rejected_and_code_dies_after_five_attempts(): void
    {
        $this->userIn($this->ubuntu, ['phone' => '+27821234567']);
        $this->postJson($this->url('/auth/otp/request'), ['phone' => '0821234567']);
        $good = $this->lastCode('+27821234567');
        $bad = $good === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->url('/auth/otp/verify'), ['phone' => '0821234567', 'code' => $bad])->assertStatus(422);
        }

        $this->postJson($this->url('/auth/otp/verify'), ['phone' => '0821234567', 'code' => $good])->assertStatus(422);
    }

    public function test_code_requests_are_limited_per_number(): void
    {
        $this->userIn($this->ubuntu, ['phone' => '+27821234567']);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson($this->url('/auth/otp/request'), ['phone' => '0821234567'])->assertStatus(202);
        }

        $this->postJson($this->url('/auth/otp/request'), ['phone' => '0821234567'])->assertStatus(429);
    }

    public function test_a_code_for_one_provider_does_not_work_at_another(): void
    {
        $this->userIn($this->ubuntu, ['phone' => '+27821234567']);
        $this->userIn($this->lebone, ['phone' => '+27821234567']);

        $this->postJson($this->url('/auth/otp/request'), ['phone' => '0821234567']);
        $code = $this->lastCode('+27821234567');

        $this->postJson($this->url('/auth/otp/verify', 'lebone.simuwork.test'), ['phone' => '0821234567', 'code' => $code])
            ->assertStatus(422);
    }

    public function test_a_token_only_works_at_the_provider_that_issued_it(): void
    {
        $this->userIn($this->ubuntu, ['phone' => '+27821234567']);
        $this->postJson($this->url('/auth/otp/request'), ['phone' => '0821234567']);
        $token = $this->postJson($this->url('/auth/otp/verify'), ['phone' => '0821234567', 'code' => $this->lastCode('+27821234567')])
            ->json('token');

        $this->getJson($this->url('/auth/me'), $this->bearer($token))->assertOk();
        $this->resetAuthGuards();

        $this->getJson($this->url('/auth/me', 'lebone.simuwork.test'), $this->bearer($token))->assertUnauthorized();
    }
}
