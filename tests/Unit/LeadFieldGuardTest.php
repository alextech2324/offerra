<?php

namespace Tests\Unit;

use Tests\TestCase;

require_once dirname(__DIR__, 2).'/templates/default/integration/LeadFieldGuard.php';

class LeadFieldGuardTest extends TestCase
{
    public function test_this_spam_payload_fails_on_phone(): void
    {
        $this->assertSame(
            'Invalid phone',
            \LeadFieldGuard::error('Kiehn', 'Kiehn', 'camille.kiehn13@mdhmx.com', '13'),
        );
    }

    public function test_short_and_long_phone_are_rejected(): void
    {
        $this->assertSame('length', \LeadFieldGuard::phoneError('1234567'));
        $this->assertSame('length', \LeadFieldGuard::phoneError('+1234567890123456'));
        $this->assertNull(\LeadFieldGuard::phoneError('+34612345678'));
        $this->assertNull(\LeadFieldGuard::phoneError('34612345678'));
    }

    public function test_name_word_and_length_limits(): void
    {
        $this->assertSame('length', \LeadFieldGuard::nameError('A', 4));
        $this->assertSame('words', \LeadFieldGuard::nameError('One Two Three Four Five', 4));
        $this->assertSame('digits', \LeadFieldGuard::nameError('John2', 4));
        $this->assertNull(\LeadFieldGuard::nameError('Kiehn', 4));
        $this->assertNull(\LeadFieldGuard::nameError("O'Neill", 5));
        $this->assertNull(\LeadFieldGuard::nameError('van der Berg', 5));
        $this->assertNull(\LeadFieldGuard::nameError('吴', 4));
    }

    public function test_email_limits(): void
    {
        $this->assertSame('format', \LeadFieldGuard::emailError('not-an-email'));
        $this->assertSame('reserved', \LeadFieldGuard::emailError('a@b.example'));
        $this->assertSame('length', \LeadFieldGuard::emailError('a@b.c'));
        $this->assertNull(\LeadFieldGuard::emailError('camille.kiehn13@mdhmx.com'));
    }

    public function test_valid_lead_passes(): void
    {
        $this->assertNull(\LeadFieldGuard::error(
            'Maria',
            'Garcia',
            'maria.garcia@gmail.com',
            '+34612345678',
        ));
    }

    public function test_euploading_farm_email_is_flagged(): void
    {
        $this->assertSame('FARM_EMAIL', \LeadFieldGuard::silentSpamReason([
            'first_name' => 'William',
            'last_name' => 'Marin',
            'email' => 'williammarin5612@euploading.com',
            'phone' => '+61412345678',
        ]));
    }

    public function test_uk_201555_drama_phone_is_flagged(): void
    {
        $this->assertSame('FARM_PHONE', \LeadFieldGuard::silentSpamReason([
            'first_name' => 'Carol',
            'last_name' => 'Price',
            'email' => 'carol.price@gmail.com',
            'phone' => '+442015557469',
        ]));
    }

    public function test_concatenated_name_digits_on_throwaway_domain_is_flagged(): void
    {
        $this->assertSame('FARM_EMAIL', \LeadFieldGuard::silentSpamReason([
            'first_name' => 'Donnie',
            'last_name' => 'George',
            'email' => 'donniegeorge2532@temp-mail.xyz',
            'phone' => '+61412345678',
        ]));
    }

    public function test_real_gmail_lead_is_not_farm_spam(): void
    {
        $this->assertNull(\LeadFieldGuard::silentSpamReason([
            'first_name' => 'Maria',
            'last_name' => 'Garcia',
            'email' => 'maria.garcia@gmail.com',
            'phone' => '+34612345678',
        ]));
    }

    public function test_blocked_albanian_farm_ip_is_flagged(): void
    {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '87.120.103.249';
        try {
            $this->assertSame('FARM_IP', \LeadFieldGuard::silentSpamReason([
                'first_name' => 'Maria',
                'last_name' => 'Garcia',
                'email' => 'maria.garcia@gmail.com',
                'phone' => '+34612345678',
            ]));
        } finally {
            unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
    }

    public function test_second_blocked_farm_ip_is_flagged(): void
    {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '31.171.153.182';
        try {
            $this->assertSame('FARM_IP', \LeadFieldGuard::silentSpamReason([
                'first_name' => 'Sue',
                'last_name' => 'Turner',
                'email' => 'sue.turner66@yatdew.com',
                'phone' => '+447723581129',
            ]));
        } finally {
            unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
    }

    public function test_faker_doubled_last_name_is_flagged(): void
    {
        $this->assertSame('FARM_NAME', \LeadFieldGuard::silentSpamReason([
            'first_name' => 'Russell Jayden',
            'last_name' => 'Smith Smith',
            'email' => 'russell_smith@goldenmarine.net',
            'phone' => '+19396993017',
        ]));
    }

    public function test_throwaway_farm_domains_are_flagged(): void
    {
        $this->assertSame('FARM_EMAIL', \LeadFieldGuard::silentSpamReason([
            'first_name' => 'Sue',
            'last_name' => 'Turner',
            'email' => 'sue.turner66@yatdew.com',
            'phone' => '+447723581129',
        ]));
        $this->assertSame('FARM_EMAIL', \LeadFieldGuard::silentSpamReason([
            'first_name' => 'Joanie',
            'last_name' => 'Littel',
            'email' => 'joanie.littel@mailrez.com',
            'phone' => '+14232056197',
        ]));
    }

    public function test_dotted_name_plus_digits_on_unknown_domain_is_flagged(): void
    {
        $this->assertSame('FARM_EMAIL', \LeadFieldGuard::silentSpamReason([
            'first_name' => 'Sue',
            'last_name' => 'Turner',
            'email' => 'sue.turner66@temp-mail.xyz',
            'phone' => '+447723581129',
        ]));
    }
}
