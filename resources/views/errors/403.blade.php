@extends('errors.layout')

@section('code', __('admin.errors.403.code'))
@section('title', __('admin.errors.403.title'))
@section('message', $exception?->getMessage() ?: __('admin.errors.403.message'))
