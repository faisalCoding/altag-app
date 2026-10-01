@extends('errors.layout')

@section('code', '419')
@section('title', 'انتهت صلاحية الصفحة')
@section('message', 'بقيت الصفحة مفتوحة مدة طويلة فانتهت جلستها. حدّثها ثم أعد المحاولة، ولن تفقد إلا ما لم يُحفظ بعد.')

@section('actions')
    <button class="primary" type="button" onclick="location.reload()">تحديث الصفحة</button>
    <a class="secondary" href="{{ url('/') }}">العودة إلى الرئيسية</a>
@endsection
