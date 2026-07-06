# ExpenseFlow Laravel Backend - Architecture Analysis

## Complete Summary for Shift/Scheduling Feature

### 1. MIDDLEWARE CHAINS

**CompanyMiddleware**: Enforces company_id match
- Super_admin bypasses
- All Eloquent models in route parameters validated
- Route binding {shift} auto-validates company_id
- Shift model MUST have company_id column

**AttendanceAccessMiddleware**: Gates check-in/check-out
- Requires attendance_enabled = true on User
- Do NOT apply to shift endpoints

**RoleMiddleware**: Checks user role
- Syntax: middleware('role:role1,role2')
- For Shifts: role:hrd|admin|super_admin (management)

**Registered Aliases** (bootstrap/app.php):
- 'role' => RoleMiddleware
- 'company' => CompanyMiddleware
- 'receipt_access' => ReceiptAccessMiddleware
- 'attendance_access' => AttendanceAccessMiddleware

### 2. ROUTES OVERVIEW

All under /v1/ prefix

Key patterns:
- Auth: /login, /logout, /me
- Employee: /employee/receipts
- Dashboard: /dashboard/* [role:finance|hrd|admin|super_admin]
- Admin: /admin/users [role:hrd|admin|super_admin]
- Attendance Dashboard: /dashboard/attendance/* [role:hrd|admin|super_admin]
- Mobile Check-in: /attendance/check-in [attendance_access]
- Mobile Employee: /attendance/* (no attendance_access gate)

### 3. COMPANY ISOLATION

User Structure:
- Each user has ONE company_id (not nullable)
- Users bound to single company (NOT multi-tenant)
- Super_admin bypasses company checks

Isolation Methods:
1. Route Model Binding: Route::get('/shifts/{shift}') auto-validates
2. Explicit Query: Shift::where('company_id', $user->company_id)
3. Relationship: $user->company->shifts

For Shift Model:
- MUST have company_id column
- Foreign key to companies table
- Unique constraint on [company_id, name]

### 4. VALIDATION PATTERN

Current Approach: NO FormRequest classes
- All validation inline with $request->validate()

Example:
```php
$validated = $request->validate([
    'month' => 'nullable|integer|min:1|max:12',
    'user_id' => 'nullable|integer|exists:users,id',
]);
```

Recommendation for Shifts: Use FormRequest classes
- Better organization and reusability
- Still validate company_id in rules

### 5. LOCATIONSERVICE

File: app/Services/LocationService.php

Method: calculateDistance(lat1, lng1, lat2, lng2) → float (meters)

Algorithm: Haversine formula
- Earth radius: 6,371,000 meters
- Accurate for < 50km

For Shifts: NOT needed (shifts are time-based)

### 6. KEY MODELS

**User**:
- company_id (required)
- role (super_admin, admin, hrd, finance, employee)
- attendance_enabled, wfh_enabled, radius_enabled (boolean)
- attendance_setting_id (FK to AttendanceSetting)

**AttendanceSetting**:
- company_id
- office_name, office_latitude, office_longitude
- work_start_time, work_end_time
- radius_meters, late_tolerance_minutes

**Attendance**:
- user_id, company_id
- date, check_in_time, check_out_time
- status (present/late), work_minutes, overtime_minutes

**Holiday**:
- company_id (null = national)
- date, name

### 7. RECOMMENDED SHIFT MODELS

**Shift**:
```
- id, company_id (required), attendance_setting_id
- name, start_time (HH:MM), end_time (HH:MM)
- is_active (boolean)
- timestamps
```

**ShiftAssignment**:
```
- id, company_id (required), user_id, shift_id
- date, is_confirmed, confirmed_at
- timestamps
```

### 8. SHIFT ROUTES (RECOMMENDED)

Admin - Manage:
```
[auth:sanctum, role:hrd|admin|super_admin, company]
GET    /admin/shifts
POST   /admin/shifts
GET    /admin/shifts/{shift}
PUT    /admin/shifts/{shift}
DELETE /admin/shifts/{shift}
```

Admin - Assign:
```
[auth:sanctum, role:hrd|admin|super_admin, company]
GET    /admin/shift-assignments
POST   /admin/shift-assignments
PUT    /admin/shift-assignments/{assignment}
DELETE /admin/shift-assignments/{assignment}
```

Employees - View:
```
[auth:sanctum, company]
GET    /shifts/my
GET    /shifts/{shift}
```

### 9. INTEGRATION POINTS

**Check-in Validation**:
- Verify shift assignment exists for today
- Check if check-in time is within shift window

**Dashboard**:
- Extend /dashboard/attendance/today with shift info

**Leave Request**:
- Check for shift conflicts during leave dates

**Timezone**:
- Use 'Asia/Jakarta' consistently

### 10. SECURITY

Company Isolation:
- All queries include company_id filter
- Route bindings auto-validate
- Test cross-company access prevention

Authorization:
- Management: role:hrd|admin|super_admin
- Mutations: role:admin|super_admin
- View: all authenticated (filtered by company)

Data Validation:
- Time format: date_format:H:i
- Date validations: after_or_equal:today
- FK existence checks
- Unique names per company

### KEY SUMMARY

**Middleware Chain**: auth:sanctum → company → role → Controller

**Company Isolation**: Route binding auto-validates, all queries filter by company_id

**Validation**: Use FormRequest classes (recommended)

**Integration**: Check-in, dashboard, leave requests

**Timezone**: Asia/Jakarta

**No FormRequest classes in codebase**, but FormRequest recommended for shifts

