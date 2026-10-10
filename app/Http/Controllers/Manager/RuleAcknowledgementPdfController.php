<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\RuleAcknowledgement;
use App\Support\CircleRules;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RuleAcknowledgementPdfController extends Controller
{
    /**
     * A person's acknowledgement of the rules, on paper: the very text they
     * read, their word, when and from where they gave it, and whether that
     * text still matches the fingerprint taken then.
     */
    public function show(RuleAcknowledgement $acknowledgement): StreamedResponse
    {
        $acknowledgement->load('user', 'version');
        $text = CircleRules::textFor($acknowledgement->version, $acknowledgement->roles);

        $pdf = LaravelMpdf::loadView('pdf.rule-acknowledgement', [
            'acknowledgement' => $acknowledgement,
            'text' => $text,
            'intact' => hash_equals($acknowledgement->content_hash, CircleRules::hashOf($text)),
        ], [], [
            'format' => 'A4',
            'default_font' => 'lamasans',
            // Left on, these hand the Arabic to a font mPDF picks itself.
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
            'useSubstitutions' => false,
        ]);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, 'acknowledgement-'.$acknowledgement->id.'.pdf');
    }
}
