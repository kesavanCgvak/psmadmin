@extends('adminlte::page')

@section('title', 'New Knowledge Entry')

@section('content_header')
    <h1>New Knowledge Entry</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    @include('admin.chatbot.knowledge._form', [
        'action' => route('admin.chatbot.knowledge.store'),
        'method' => 'POST',
        'entry' => null,
        'submitLabel' => 'Save',
    ])
@stop
