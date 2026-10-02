# OJTRACK — Complete System Documentation

**University of Science and Technology of Southern Philippines**  
**Jasaan Campus · Academic Year 2025–2026**

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Database Schema](#2-database-schema)
3. [File Structure](#3-file-structure)
4. [User Roles & Permissions](#4-user-roles--permissions)
5. [Features](#5-features)
6. [API Endpoints](#6-api-endpoints)
7. [Configuration](#7-configuration)
8. [Authentication Flow](#8-authentication-flow)
9. [Notification System](#9-notification-system)
10. [Message System](#10-message-system)
11. [Demo Accounts](#11-demo-accounts)

---

## 1. System Overview

OJTRACK is a web-based OJT (On-the-Job Training) Management and Monitoring System built with PHP and MySQL. It provides a centralized platform for managing the complete OJT process — from requirements submission to completion.

**Tech Stack:**
- **Backend:** PHP 8+ (mysqli)
- **Database:** MySQL (utf8mb4)
- **Frontend:** HTML, CSS, JavaScript (vanilla)
- **Fonts:** Outfit (display), Inter (body), JetBrains Mono (code)
- **Server:** XAMPP (Apache + MySQL)

---

## 2. Database Schema

**Database:** `ojtrack`  
**File:** `database/ojtrack.sql`

### Tables (16 total)

#### users
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(100) NOT NULL | |
| email | VARCHAR(100) NOT NULL UNIQUE | |
| password | VARCHAR(255) NOT NULL | bcrypt hash |
| role | ENUM('student','coordinator','company','admin') NOT NULL | |
| status | ENUM('active','inactive') DEFAULT 'active' | |
| created_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

#### students
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| user_id | INT NOT NULL | FK → users(id) |
| student_id_no | VARCHAR(20) NOT NULL UNIQUE | |
| program | VARCHAR(100) DEFAULT 'BS Information Technology' | |
| year_level | VARCHAR(20) DEFAULT '4th Year' | |
| contact_number | VARCHAR(20) | |
| coordinator_id | INT | FK → coordinators(id) |
| company_id | INT | FK → companies(id) |
| ojt_status | ENUM('pending','not_started','ongoing','completed','on_hold','withdrawn') DEFAULT 'pending' | |\n| onboarding_completed_at | TIMESTAMP NULL | Set when a new student's required OJT documents are all approved |
| required_hours | INT DEFAULT 486 | |
| rendered_hours | DECIMAL(8,2) DEFAULT 0.00 | |
| ojt_start_date | DATE | |
| ojt_end_date | DATE | |

#### coordinators
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| user_id | INT NOT NULL | FK → users(id) |
| department | VARCHAR(100) | |

#### companies
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| user_id | INT NOT NULL | FK → users(id) |
| company_name | VARCHAR(150) NOT NULL | |
| supervisor_name | VARCHAR(100) | |
| location | VARCHAR(200) | |
| contact_number | VARCHAR(20) | |
| status | ENUM('active','inactive') DEFAULT 'active' | |

#### ojt_requirements
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| student_id | INT NOT NULL | FK → students(id) |
| document_name | VARCHAR(150) NOT NULL | |
| file_path | VARCHAR(255) | |
| deadline | DATE | |
| submitted_at | TIMESTAMP NULL | |
| status | ENUM('pending','approved','rejected') DEFAULT 'pending' | |
| remarks | TEXT | |
| reviewed_by | INT | FK → users(id) |
| reviewed_at | TIMESTAMP NULL | |

#### attendance
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| student_id | INT NOT NULL | FK → students(id) |
| date | DATE NOT NULL | |
| time_in | TIME | |
| time_out | TIME | |
| morning_in | TIME | |
| morning_out | TIME | |
| afternoon_in | TIME | |
| afternoon_out | TIME | |
| hours_rendered | DECIMAL(4,2) DEFAULT 0.00 | |
| remarks | VARCHAR(255) | |
| status | ENUM('present','absent','excused') DEFAULT 'present' | |
| | | UNIQUE KEY (student_id, date) |

#### journal_entries
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| student_id | INT NOT NULL | FK → students(id) |
| entry_date | DATE NOT NULL | |
| week_number | INT | |
| activities | TEXT NOT NULL | |
| learnings | TEXT NOT NULL | |
| challenges | TEXT NOT NULL | |
| hours_rendered | DECIMAL(4,2) DEFAULT 0.00 | |
| status | ENUM('pending','approved','rejected') DEFAULT 'pending' | |
| coordinator_remarks | TEXT | |
| submitted_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

#### reports
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| student_id | INT NOT NULL | FK → students(id) |
| report_name | VARCHAR(150) NOT NULL | |
| report_type | ENUM('initial','midterm','final') NOT NULL | |
| file_path | VARCHAR(255) | |
| deadline | DATE | |
| submitted_at | TIMESTAMP NULL | |
| status | ENUM('pending','approved','rejected','for_review') DEFAULT 'pending' | |
| remarks | TEXT | |
| reviewed_by | INT | FK → users(id) |

#### evaluations
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| student_id | INT NOT NULL | FK → students(id) |
| company_id | INT NOT NULL | FK → companies(id) |
| evaluator_id | INT NOT NULL | FK → users(id) |
| evaluation_type | ENUM('midterm','final') NOT NULL | |
| technical_skills | INT | |
| work_ethic | INT | |
| communication | INT | |
| teamwork | INT | |
| initiative | INT | |
| adaptability | INT | |
| overall_score | DECIMAL(5,2) | |
| comments | TEXT | |
| status | ENUM('pending','completed') DEFAULT 'pending' | |
| evaluated_at | TIMESTAMP NULL | |

#### message_threads
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(150) | |
| thread_type | ENUM('group','direct') DEFAULT 'group' | |
| created_by | INT | FK → users(id) |
| created_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

#### thread_members
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| thread_id | INT NOT NULL | FK → message_threads(id) |
| user_id | INT NOT NULL | FK → users(id) |
| joined_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |
| | | UNIQUE KEY (thread_id, user_id) |

#### messages
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| thread_id | INT NOT NULL | FK → message_threads(id) |
| sender_id | INT NOT NULL | FK → users(id) |
| message | TEXT NOT NULL | |
| sent_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

#### message_reads
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| message_id | INT NOT NULL | FK → messages(id) |
| user_id | INT NOT NULL | FK → users(id) |
| read_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |
| | | UNIQUE KEY (message_id, user_id) |

#### announcements
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| title | VARCHAR(200) NOT NULL | |
| body | TEXT NOT NULL | |
| tag | VARCHAR(50) DEFAULT 'General' | |
| target_role | ENUM('all','student','coordinator','company','admin') DEFAULT 'all' | |
| created_by | INT NOT NULL | FK → users(id) |
| is_active | TINYINT(1) DEFAULT 1 | |
| created_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

#### notifications
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| user_id | INT NOT NULL | FK → users(id) |
| message | TEXT NOT NULL | |
| notif_type | ENUM('info','warning','error','success') DEFAULT 'info' | |
| link | VARCHAR(500) DEFAULT NULL | |
| is_read | TINYINT(1) DEFAULT 0 | |
| created_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

#### activity_log
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| user_id | INT | FK → users(id) |
| action | VARCHAR(100) NOT NULL | |
| details | TEXT | |
| ip_address | VARCHAR(45) | |
| created_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

### Entity Relationships
- `users` is the central table; all roles reference it
- `students`, `coordinators`, `companies` each have 1:1 with `users` via `user_id`
- `students.coordinator_id` → `coordinators.id` (many-to-one)
- `students.company_id` → `companies.id` (many-to-one)
- `ojt_requirements.student_id` → `students.id`
- `attendance.student_id` → `students.id`
- `journal_entries.student_id` → `students.id`
- `reports.student_id` → `students.id`
- `evaluations.student_id` → `students.id`, `evaluations.company_id` → `companies.id`
- `message_threads` → `thread_members` → `messages` → `message_reads`
- `notifications.user_id` → `users.id`
- `activity_log.user_id` → `users.id`

---

## 3. File Structure

### Root Files
| File | Purpose |
|------|---------|
| `index.php` | Entry point; redirects to role-based dashboard or login |
| `login.php` | Login page with email/student ID + password |
| `logout.php` | Destroys session and redirects to login |

### Config Files
| File | Purpose |
|------|---------|
| `config/db.php` | Database connection + helper functions |
| `config/auth.php` | Session management, authentication |

### Includes
| File | Purpose |
|------|---------|
| `includes/header.php` | Sidebar nav, topbar, notification/user dropdowns |
| `includes/footer.php` | Closes layout, loads main.js |

### Student Pages (10 files)
| File | Purpose |
|------|---------|
| `student/dashboard.php` | OJT overview, hours progress, quick time-in |
| `student/requirements.php` | View/submit OJT requirement documents |
| `student/attendance.php` | Log daily attendance, quick time record |
| `student/progress.php` | OJT milestone tracker, progress bars |
| `student/journal.php` | Daily journal entries |
| `student/reports.php` | Submit narrative reports |
| `student/evaluation.php` | View company evaluations |
| `student/messages.php` | Chat with coordinator/supervisor |
| `student/announcements.php` | View announcements |
| `student/profile.php` | Update profile and password |

### Coordinator Pages (9 files)
| File | Purpose |
|------|---------|
| `coordinator/dashboard.php` | Stats, pending requirements, student progress |
| `coordinator/students.php` | Student management, edit assignments |
| `coordinator/requirements.php` | Review/approve/reject requirements, add new |
| `coordinator/monitoring.php` | Journal monitoring, approve/reject |
| `coordinator/attendance.php` | View attendance for all students |
| `coordinator/reports.php` | Review/approve/reject reports |
| `coordinator/messages.php` | Chat with students and companies |
| `coordinator/announcements.php` | Post/pin/delete announcements |
| `coordinator/profile.php` | Update profile and password |

### Company Pages (7 files)
| File | Purpose |
|------|---------|
| `company/dashboard.php` | Trainee stats, today's attendance |
| `company/students.php` | View assigned trainees |
| `company/attendance.php` | View trainee attendance |
| `company/evaluation.php` | Submit evaluations (6 criteria) |
| `company/messages.php` | Chat with trainees/coordinators |
| `company/announcements.php` | View announcements |
| `company/profile.php` | Update company info and password |

### Admin Pages (8 files)
| File | Purpose |
|------|---------|
| `admin/dashboard.php` | System overview, stats, health |
| `admin/users.php` | CRUD for all users |
| `admin/students.php` | View/manage all students |
| `admin/coordinators.php` | Add/edit coordinators |
| `admin/companies.php` | Register/edit companies |
| `admin/announcements.php` | Post system-wide announcements |
| `admin/activity.php` | View activity log |
| `admin/profile.php` | Update admin profile |

### API Files (2 files)
| File | Purpose |
|------|---------|
| `api/messages.php` | Create threads, send messages, fetch new |
| `api/notifications.php` | Fetch notifications, mark read |

### Assets
| File | Purpose |
|------|---------|
| `assets/css/style.css` | Full stylesheet (1514 lines) |
| `assets/js/main.js` | JavaScript (334 lines) |
| `assets/images/logo.png` | OJTrack logo (square) |
| `assets/images/branging.png` | OJTrack branding (horizontal) |

---

## 4. User Roles & Permissions

### Student
- View personal OJT dashboard with hours progress
- Submit/upload OJT requirement documents
- Log daily attendance (quick time record with auto-detect)
- Write daily journal entries
- Submit narrative reports (initial, midterm, final)
- View company evaluations
- Message coordinator and company supervisor
- View announcements
- Update profile and password

### Coordinator
- View dashboard with supervised students' stats
- Manage student assignments (company, status, hours, dates)
- Review/approve/reject student requirements
- Add new requirements for students
- Monitor and approve/reject journal entries
- View attendance records for all supervised students
- Review/approve/reject narrative reports
- Create group/direct message threads
- Post/pin/delete announcements
- Update profile and password

### Company (Industry Partner)
- View dashboard with assigned trainees
- View trainee details (attendance, reports, evaluations)
- View attendance records for all trainees
- Submit midterm/final evaluations (6 criteria + comments)
- Message trainees and coordinators
- View announcements
- Update company profile and password

### Admin
- Full system overview and health monitoring
- CRUD for all user accounts
- View and manage all students and assignments
- Manage coordinators and companies
- Post system-wide announcements
- View complete activity log
- Update admin profile and password

---

## 5. Features

### OJT Requirements Management
- Students upload documents (PDF, JPG, PNG, DOC, DOCX)
- Coordinators review, approve, or reject with remarks
- Coordinators can add custom requirements for students
- Status tracking: pending → approved/rejected

### Attendance System
- Quick time record with auto-detection:
  - Before 12:00 PM → Morning In
  - 12:00–1:00 PM → Morning Out
  - 1:00–5:00 PM → Afternoon In
  - After 5:00 PM → Afternoon Out
- Hours auto-calculated (1-hour lunch deduction if > 5 hours)
- Month filtering and search
- Cumulative hours tracking

### Daily Journal
- Students write entries (activities, learnings, challenges)
- Auto week number calculation
- Coordinator approval/rejection with feedback

### Reports
- Three types: initial, midterm, final
- File upload with coordinator review workflow

### Evaluations
- 6 criteria: technical skills, work ethic, communication, teamwork, initiative, adaptability
- Slider-based scoring (0-100)
- Midterm and final evaluation cycles

### Messaging System
- Group and direct message threads
- Real-time sending via API
- Unread message badges
- Message read tracking

### Announcements
- Targeted by role
- Tag/category filtering
- Search functionality

### Notifications
- Bell icon with unread counter
- Click to navigate to linked page
- Mark as read (single/all)

---

## 6. API Endpoints

### `api/messages.php`
| Method | Parameters | Description |
|--------|------------|-------------|
| POST | `action=create_thread`, `name`, `thread_type`, `description`, `members[]`, `initial_message` | Create new thread |
| POST | `thread_id`, `message` | Send message |
| GET | `thread_id`, `since` | Fetch new messages since ID |

### `api/notifications.php`
| Method | Parameters | Description |
|--------|------------|-------------|
| GET | (none) | Fetch notifications + unread count |
| POST | `action=mark_read`, `id` (optional) | Mark single/all as read |
| POST | `action=clear` | Delete all notifications |

---

## 7. Configuration

### Database (`config/db.php`)
```php
DB_HOST = 'localhost'
DB_USER = 'root'
DB_PASS = ''
DB_NAME = 'ojtrack'
```

### Helper Functions
- `query($sql, $params, $types)` — Execute query, return all rows
- `query_one($sql, $params, $types)` — Execute query, return first row
- `insert($sql, $params, $types)` — Insert and return last insert ID
- `e($str)` — HTML escape
- `format_date($date)` — Format as "M d, Y"
- `format_time($time)` — Format as "h:i A"
- `create_notification($user_id, $message, $type, $link)` — Create notification
- `time_ago($datetime)` — Relative time display
- `status_badge($status)` — Return colored badge HTML

---

## 8. Authentication Flow

### Login
1. User submits email/student ID + password
2. System queries `users` table with role-specific joins
3. Password verified via `password_verify()` or demo passwords
4. Session set: `user_id`, `role`, `name`, `sub`
5. Activity logged
6. Redirect to role-based dashboard

### Authorization
- Each page calls `require_login(['role'])`
- `define('OJTRACK', true)` prevents direct access to includes
- Session-based with role verification

---

## 9. Notification System

- **Types:** info, warning, error, success
- **Display:** Bell icon with unread count, dropdown with 6 recent
- **Actions:** Mark single/all as read, click to navigate
- **Triggers:** Requirement status, journal review, report review, evaluation, messages, announcements, assignment updates

---

## 10. Message System

- **Thread types:** group, direct
- **Features:** Real-time sending, unread tracking, auto-mark read
- **UI:** Thread list sidebar + chat area with bubbles
- **Mobile:** Responsive, stacks vertically

---

## 11. Demo Accounts

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@ustp.edu.ph | admin123 |
| Coordinator | jocelyn.rivera@ustp.edu.ph | coord123 |
| Coordinator | mark.santos@ustp.edu.ph | coord123 |
| Company | supervisor@mindanaoict.com | company123 |
| Student | ana.reyes@ustp.edu.ph | student123 |

---

## Summary Statistics

| Metric | Value |
|--------|-------|
| Total PHP files | 42 |
| Database tables | 16 |
| User roles | 4 |
| API endpoints | 2 files, 5 endpoints |
| CSS file | 1514 lines |
| JS file | 334 lines |
| Default required hours | 486 |
| Evaluation criteria | 6 dimensions |
| Report types | 3 (initial, midterm, final) |
\n\n### Student First-Login Activation\n- New student accounts use `student/onboarding.php` before the main dashboard.\n- The activation UI follows a four-step progress pattern: **Student Details → Required Documents → Coordinator Review → Dashboard Access**.\n- Students can upload or resubmit PDF, JPG, PNG, DOC, and DOCX requirement files directly from the activation screen.\n- The main student navigation is not rendered while activation is incomplete.\n- A coordinator must approve every assigned `ojt_requirements` record before `students.onboarding_completed_at` is set.\n- Existing accounts can be preserved as already onboarded during the rollout using `scratch/migrate_student_onboarding.php`.\n