# Employee Portal

A full stack employee management system with role based dashboards and AI assisted HR workflows. Built with PHP and SQLite, with four features powered by Groq's LLM API.

## Overview

Employee Portal streamlines everyday HR operations such as leave management, document custody tracking, and admin approvals, while using AI to keep every automated response grounded in the company's own policies rather than generic advice.

## Features

### Authentication and Access Control
* Email and password signup and login with hashed passwords
* Three tier roles: Employee, Admin, and a permanent Superadmin
* Employees can request admin access; only the Superadmin or existing Admins approve or reject these requests

### Leave Management
* Submit leave requests with an optional PDF or Word attachment
* Track request status (Pending, Approved, or Rejected) with full history
* In app messaging thread between employee and admin per request, with read and unread notifications

### Document Custody
* Employees register documents they hold and can rename, delete, or hand them over to a coworker
* Every handover is logged with reason, date, and time
* Admins get a portal wide view of current custody and full handover history, searchable by employee

### Company Rules Engine
* Admins define and manage company policies through a dedicated interface
* These rules are dynamically injected into every AI feature's context, so AI output stays grounded in actual policy

### AI Powered Features (Groq API)
* AI Helper: a policy aware chatbot answering employee questions from the admin defined rule set
* AI Judgement: employees describe a workplace dispute (optionally with multiple perspectives) and get an AI mediated judgement, suggested questions, and recommended action
* AI Verdict: one click AI analysis of a pending leave request, surfacing inconsistencies, counter questions, and an approve or reject recommendation for admins
* AI advice can also be posted directly into an admin employee message thread for live guidance

### Dashboard and Analytics
* Visual statistics on the admin dashboard, including leave requests by department, status breakdown, document custody activity over time, and documents per department

## Tech Stack

* Backend: PHP (PDO)
* Database: SQLite
* AI: Groq API (LLM integration)
* Frontend: HTML, CSS, JavaScript, Chart.js
* Design: Custom UI system (cream and yellow palette, DM Sans typography) applied consistently across all pages


