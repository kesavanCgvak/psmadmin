@extends('adminlte::page')

@section('title', 'Edit Knowledge Entry')

@section('content_header')
    <h1>Edit Knowledge Entry</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    @include('admin.chatbot.knowledge._form', [
        'action' => route('admin.chatbot.knowledge.update', $knowledge),
        'method' => 'PUT',
        'entry' => $knowledge,
        'submitLabel' => 'Update',
    ])
@stop
