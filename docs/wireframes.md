# Low-fidelity wireframes (for approval)

Desktop is shown first; below 992 px the sidebar becomes a slide-in menu opened by the hamburger button,
and tables become stacked cards.

## Login
```
        [truck] Job Tracker
 +--------------------------------+
 | Welcome back                   |
 | Sign in to manage your jobs.   |
 |                                |
 | Username  [____________________]|
 | Password  [______________][Show]|
 |                                |
 | [          Sign in            ]|
 +--------------------------------+
```

## App shell (all signed-in pages)
```
+----------+-----------------------------------------------+
| [truck]  |  Page title                       (A) Name  v |
| Job      +-----------------------------------------------+
| Tracker  |  [ flash message ]                            |
|          |                                               |
| Dashboard|  page content                                 |
| Jobs  *  |                                               |
| ...      |                                               |
| Account  |                                               |
+----------+-----------------------------------------------+
  * sidebar items appear only if the user holds the permission
```

## Dashboard (Phase 3 target)
```
 [Total jobs] [Ready for delivery] [More than 30 days] [In transit] [Delivered]
     128            14                   61                 9          44
 (each card is a link to its list)

 +-- Needs attention ------------------+ +-- Money --------------+
 | overdue / soonest available dates   | | Outstanding balance   |
 +-------------------------------------+ | Storage fees          |
 +-- Recent activity -------------------+ +----------------------+
```

## Job list (Phase 3 target) - desktop table, phone cards
```
 [Search...........] [Status v] [Date range] [From/To]  [Export] [+ New job]
 Job#  Customer      Pickup   1st avail   From -> To     CF   Balance  Status
 1042  Ada Smith     Oct 02   Nov 06 (14d) NY -> LA      900  $1,200   [Booked]

 phone:  +-----------------------------+
         | #1042          [Booked]     |
         | Ada Smith                   |
         | 1st available   Nov 06      |
         | NY -> LA        $1,200      |
         +-----------------------------+
```

## Job form (Phase 2 target)
```
 Customer   [First name] [Last name]
 Route      [From] [To]  [Job location]
 Dates      [Pickup date] [1st available date]
 Cargo      [CF]
 Money      [Balance] [Storage fee]          (hidden without financial permission)
 Notes      [..................................]
            [Cancel] [Save job]
```
