@extends('layouts.app')

@section('title', 'Draft Reports')
@section('page_label', 'Draft Reports')
@section('page_heading', 'My Draft Reports')
@section('page_subheading', 'Continue your unfinished reports saved for this account on this device.')

@section('content')
    @include('reports.partials.civilian-drafts')
@endsection
