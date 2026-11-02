@extends('parent.layout')
@section('title','Sign in')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PARENT PORTAL</div><h1>See your child's classes, results and fees</h1><p>Sign in with your child's student ID and date of birth. Each child has their own sign-in.</p></div></div>
<section style="max-width:480px"><form method="post" action="{{ route('parent.login.submit') }}" style="flex-direction:column;align-items:stretch">@csrf
<label>Student ID<input name="student_code" value="{{ old('student_code') }}" autocomplete="username" autocapitalize="characters" required maxlength="100" placeholder="For example CL-1031"></label>
<label>Date of birth<input type="date" name="date_of_birth" value="{{ old('date_of_birth') }}" autocomplete="bday" required></label>
<button type="submit">Sign in</button></form>
<p><small>Your student ID is on fee requests and receipts from the centre. If you can't sign in, ask the centre to check the date of birth they have on record. For your security, repeated wrong attempts lock the ID for 15 minutes.</small></p></section>
@endsection
