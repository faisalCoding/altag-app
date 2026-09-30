<?php

/**
 * mPDF, which renders the printed attendance report.
 *
 * The only reason this file exists rather than the package's defaults is the
 * font: a printed report should be set in the same face as the screen it was
 * read on. Lama Sans ships as woff2 for the browser and mPDF cannot read that,
 * so the regular and bold weights are kept beside it as TTF.
 */
return [
    'mode' => 'utf-8',
    'format' => 'A4',
    'default_font_size' => '10',
    'default_font' => 'lamasans',
    'margin_left' => 10,
    'margin_right' => 10,
    'margin_top' => 12,
    'margin_bottom' => 12,
    'margin_header' => 0,
    'margin_footer' => 0,
    'orientation' => 'P',
    'title' => '',
    'subject' => '',
    'author' => '',
    'watermark' => '',
    'show_watermark' => false,
    'show_watermark_image' => false,
    'watermark_font' => 'lamasans',
    'display_mode' => 'fullpage',
    'watermark_text_alpha' => 0.1,
    'watermark_image_path' => '',
    'watermark_image_alpha' => 0.2,
    'watermark_image_size' => 'D',
    'watermark_image_position' => 'P',

    'custom_font_dir' => public_path('fonts/lama-sans'),
    'custom_font_data' => [
        'lamasans' => [
            'R' => 'LamaSans-Regular.ttf',
            'B' => 'LamaSans-Bold.ttf',
            // Arabic shaping and right-to-left, which mPDF will not do for a
            // custom font unless it is told the font is used for them.
            'useOTL' => 0xFF,
            'useKashida' => 75,
        ],
    ],

    'auto_language_detection' => false,
    'temp_dir' => storage_path('app'),
    'pdfa' => false,
    'pdfaauto' => false,
    'use_active_forms' => false,
];
