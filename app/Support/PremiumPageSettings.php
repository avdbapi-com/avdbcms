<?php

namespace App\Support;

class PremiumPageSettings
{
    public static function defaults(): array
    {
        return [
            'premium_plan_switcher_enabled' => '1',
            'premium_timeline_enabled' => '1',
            'premium_feature_uploads_enabled' => '1',
            'premium_feature_downloads_enabled' => '1',
            'premium_feature_ad_free_enabled' => '1',
            'premium_comparison_uploads_row_enabled' => '1',
            'premium_comparison_file_size_row_enabled' => '1',
            'premium_comparison_storage_row_enabled' => '1',
            'premium_comparison_downloads_row_enabled' => '0',
            'premium_comparison_ads_row_enabled' => '1',
            'premium_comparison_downloads_free_enabled' => '0',
            'premium_comparison_downloads_premium_enabled' => '1',
            'premium_comparison_ads_free_enabled' => '1',
            'premium_comparison_ads_premium_enabled' => '0',
            'premium_page_kicker' => 'Premium',
            'premium_page_title' => ':site Premium',
            'premium_page_subtitle' => 'Unleash the full potential of your creative journey with our premium plan',
            'premium_disabled_message' => 'Premium subscriptions are currently unavailable.',
            'premium_checkout_unavailable_message' => 'Premium checkout is currently unavailable. Please check back later.',
            'premium_empty_plans_message' => 'Premium plans are being prepared.',
            'premium_trial_badge_template' => ':days days trial',
            'premium_then_label' => 'Then',
            'premium_cancel_anytime_label' => 'Cancel anytime',
            'premium_card_or_paypal_label' => 'Card or PayPal',
            'premium_paypal_label' => 'PayPal',
            'premium_billing_heading' => 'Billing',
            'premium_annual_savings_text' => 'Save 2 months with the annual subscription',
            'premium_feature_uploads_title' => 'Uploads',
            'premium_feature_uploads_description' => 'Unlimited video uploads and storage space',
            'premium_feature_uploads_icon' => 'fa-upload',
            'premium_feature_downloads_title' => 'Downloads',
            'premium_feature_downloads_description' => "Save videos for when you really need them - like when you're on a plane or commuting.",
            'premium_feature_downloads_icon' => 'fa-download',
            'premium_feature_ad_free_title' => 'Videos without ads',
            'premium_feature_ad_free_description' => 'Enjoy your favorite content even more, without ads',
            'premium_feature_ad_free_icon' => 'fa-arrow-pointer',
            'premium_plan_unavailable_label' => 'Plan unavailable',
            'premium_plan_unavailable_note' => 'Checkout is not ready for this plan yet.',
            'premium_pay_card_label' => 'Pay with card',
            'premium_pay_paypal_label' => 'Pay with PayPal',
            'premium_start_card_label' => 'Start with card',
            'premium_start_paypal_label' => 'Start with PayPal',
            'premium_billing_trial_summary' => 'Your billing starts on :date for the :period plan.',
            'premium_billing_without_trial_summary' => 'Your billing starts today for the :period plan.',
            'premium_billing_charge_note' => 'You will be charged automatically at the end of each period.',
            'premium_billing_refund_note' => "Payments won't be refunded for partial billing periods. Cancel anytime from your account.",
            'premium_timeline_trial_title' => ':days days trial',
            'premium_timeline_trial_description' => 'Start your free trial of :site Premium and enjoy all premium features.',
            'premium_timeline_reminder_title' => ':date',
            'premium_timeline_reminder_description' => 'We will email you :days days before your trial ends.',
            'premium_timeline_start_title' => ':date',
            'premium_timeline_start_description' => 'Your subscription begins unless you canceled it during the trial period.',
            'premium_comparison_title' => 'Plans comparison',
            'premium_comparison_free_label' => 'Free plan',
            'premium_comparison_premium_label' => 'Premium plan',
            'premium_comparison_uploads_label' => 'Max Video Uploads',
            'premium_comparison_file_size_label' => 'Max File Size Per Upload',
            'premium_comparison_storage_label' => 'Video Storage Space',
            'premium_comparison_downloads_label' => 'Downloads',
            'premium_comparison_ads_label' => 'Ads on videos',
            'premium_unlimited_label' => 'Unlimited',
        ];
    }

    public static function booleanKeys(): array
    {
        return [
            'premium_plan_switcher_enabled',
            'premium_timeline_enabled',
            'premium_feature_uploads_enabled',
            'premium_feature_downloads_enabled',
            'premium_feature_ad_free_enabled',
            'premium_comparison_uploads_row_enabled',
            'premium_comparison_file_size_row_enabled',
            'premium_comparison_storage_row_enabled',
            'premium_comparison_downloads_row_enabled',
            'premium_comparison_ads_row_enabled',
            'premium_comparison_downloads_free_enabled',
            'premium_comparison_downloads_premium_enabled',
            'premium_comparison_ads_free_enabled',
            'premium_comparison_ads_premium_enabled',
        ];
    }

    public static function textKeys(): array
    {
        return array_values(array_diff(array_keys(self::defaults()), self::booleanKeys()));
    }

    public static function iconKeys(): array
    {
        return [
            'premium_feature_uploads_icon',
            'premium_feature_downloads_icon',
            'premium_feature_ad_free_icon',
        ];
    }

    public static function featureKeys(): array
    {
        return ['uploads', 'downloads', 'ad_free'];
    }

    public static function iconOptions(): array
    {
        return [
            'fa-upload' => 'Upload',
            'fa-download' => 'Download',
            'fa-arrow-pointer' => 'Pointer',
            'fa-ban' => 'No ads',
            'fa-shield-halved' => 'Shield',
            'fa-bolt' => 'Bolt',
            'fa-crown' => 'Crown',
            'fa-star' => 'Star',
            'fa-film' => 'Film',
            'fa-lock-open' => 'Unlock',
        ];
    }

    public static function bool(array $settings, string $key): bool
    {
        $defaults = self::defaults();

        return (string) ($settings[$key] ?? $defaults[$key] ?? '0') === '1';
    }

    public static function text(array $settings, string $key): string
    {
        $defaults = self::defaults();

        return (string) ($settings[$key] ?? $defaults[$key] ?? '');
    }

    public static function format(string $value, array $replacements = []): string
    {
        $tokens = [];

        foreach ($replacements as $key => $replacement) {
            $tokens[':' . $key] = (string) $replacement;
        }

        return strtr($value, $tokens);
    }
}
