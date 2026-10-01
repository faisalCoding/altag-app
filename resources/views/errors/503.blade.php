@extends('errors.layout')

@section('code', '503')
@section('title', 'المنصة قيد الصيانة')
@section('message', 'نجري تحديثاً سريعاً على المنصة، وستعود خلال دقائق بإذن الله.')

@section('actions')
    <button class="primary" type="button" onclick="location.reload()">إعادة المحاولة</button>
@endsection
