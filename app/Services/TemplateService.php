<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\NotificationTemplate;

class TemplateService
{
    /**
     * Create a new versioned template.
     */
    public function createTemplate(
        Clinic $clinic,
        string $name,
        string $code,
        ?string $subject,
        string $content
    ): NotificationTemplate {
        return NotificationTemplate::create([
            'clinic_id' => $clinic->id,
            'name' => $name,
            'code' => $code,
            'subject' => $subject,
            'content' => $content,
        ]);
    }

    /**
     * Render subject and content by replacing placeholders.
     */
    public function renderTemplate(NotificationTemplate $template, array $placeholders): array
    {
        $subject = $template->subject ?? '';
        $content = $template->content;

        foreach ($placeholders as $key => $value) {
            $placeholder = '{{'.$key.'}}';
            $subject = str_replace($placeholder, (string) $value, $subject);
            $content = str_replace($placeholder, (string) $value, $content);
        }

        return [
            'subject' => $subject,
            'content' => $content,
        ];
    }
}
