<?php

it('answers a missing page in Arabic, in the academy\'s own frame', function () {
    $this->get('/no-such-page-here')
        ->assertNotFound()
        ->assertSee('<html lang="ar" dir="rtl">', false)
        ->assertSee('الصفحة غير موجودة')
        ->assertSee('العودة إلى الرئيسية');
});

it('offers a refresh on an expired page, in Arabic', function () {

    $html = view('errors.419')->render();

    expect($html)->toContain('انتهت صلاحية الصفحة')->toContain('location.reload()');
});
