# DNCC Municipal Management System
The DNCC Municipal Management System is a comprehensive digital platform designed to streamline project planning, asset management, maintenance operations, employee coordination, and citizen engagement within the Dhaka North City Corporation. The system supports two types of users: Citizens, who can submit reports, track the status of their complaints, and stay informed about municipal actions, enhancing transparency and public trust, and Admin Users, DNCC officials responsible for managing projects, assets, employees, maintenance tasks, and citizen interactions through a structured and efficient interface. To ensure the platform accurately reflects DNCC’s actual operational workflow, information was gathered through an informal interview with an Executive Engineer from the Planning and Design Department of DNCC. This project was developed based on the insights he provided, and the resulting workflow diagram is included below.

<img width="1130" height="1055" alt="dncc" src="https://github.com/user-attachments/assets/1307048d-b4a2-4a4a-a29d-62dea2276ba0" />


## Features
- Login and Registration: Secure login and account creation for both citizens and admin users, ensuring controlled access to system functionalities.

Sign-in:

![olog](https://github.com/user-attachments/assets/02c22851-7051-467c-879b-803bd55a938b)

Sign-up:

![register](https://github.com/user-attachments/assets/61e595cc-f5d9-4c2f-bba9-3436a6fdcc81)

- Dashboard: For admin, a centralized overview displaying key statistics such as active, completed, and upcoming projects, total tasks, recent citizen reports, and overall municipal activity. For citizens, a centralized overview displaying report stats and profiles.

![dashboard](https://github.com/user-attachments/assets/94c2071a-4c53-4831-99b4-48b13f4652f3)

![dashC](https://github.com/user-attachments/assets/04633e75-6ebc-4e5c-b1fb-a30a4d0f0c2b)


- Project Management: Allows admin to create and oversee development projects by assigning zones, packages, segments, teams, and tasks. Includes tracking of budgets, timelines, and progress for full project transparency.

![pr1](https://github.com/user-attachments/assets/2202c65d-c924-425b-9f42-5ed016c38d20)

![pr2](https://github.com/user-attachments/assets/6ef94994-7a89-4a23-a58f-d7151529331d)

![pr3](https://github.com/user-attachments/assets/78baacc6-58b1-4230-89ee-0cdb2183d6a3)

![pr4](https://github.com/user-attachments/assets/371a9950-98dc-479f-8098-b30827ad5851)

![pr5](https://github.com/user-attachments/assets/5ac0bc83-dc55-4941-bcd8-889d4843a444)

![pr6](https://github.com/user-attachments/assets/eb876a76-dbd3-410e-9e77-776637601064)

![pa1](https://github.com/user-attachments/assets/5cba802b-c673-418f-b674-11c6c605d9a0)

![pa2](https://github.com/user-attachments/assets/a2fe3bda-af96-46f8-8321-f93ba0f15f49)


  
![t1](https://github.com/user-attachments/assets/1d454153-68c9-4b7e-8f9c-bd33f4c436be)

![t2](https://github.com/user-attachments/assets/d74c81bd-4e80-4800-bac0-3da36795bdb6)

![r1](https://github.com/user-attachments/assets/3bb3b3be-e53c-4cab-adff-3efcb6f999f8)


![e1](https://github.com/user-attachments/assets/30aba709-c4a6-4638-b5d1-b6ebc5d51739)

  

- Assets Management: A structured module for creating, storing, and managing all municipal assets used across projects and maintenance activities, helping track usage and resource allocation.

![as1](https://github.com/user-attachments/assets/fe2218be-f47e-454b-9ca3-bde421468f3a)

![as2](https://github.com/user-attachments/assets/4b634490-176b-492e-a56d-5d9d09eb04c0)



- Maintenance Management: Displays all maintenance tasks with details such as type, asset used, assigned project, start & end dates, cost, and status. Admin can update progress, assign workers, and ensure timely completion.

![m1](https://github.com/user-attachments/assets/c8e65f35-f7c5-4a4d-ad7b-ac87665d6248)


- Employee Management: Provides detailed employee profiles, including designation, salary, current roles, and work history. Admin can assign or remove employees from tasks, segments, or projects.

![e2](https://github.com/user-attachments/assets/a774860f-acf7-4c5a-8b5a-0120bdf43371)

![e3](https://github.com/user-attachments/assets/a9f756a4-bc57-436c-99f2-e76eaf4ca475)

![e4](https://github.com/user-attachments/assets/23ce74f3-ea25-4c3f-8087-c6f008055add)

- Citizen Reports: Citizens can submit reports directly through the system. Admin can review these reports, provide feedback, and convert them into maintenance tasks when needed, improving responsiveness and public engagement.

Admin:

![ca1](https://github.com/user-attachments/assets/93e5d1c4-e4cf-4a72-a27b-dd7bebbf3829)

![ca2](https://github.com/user-attachments/assets/c64f06ae-3449-4de3-a06d-062d3083b7be)

Citizen:

![cc2](https://github.com/user-attachments/assets/c80db6bb-a355-4186-96c8-10f67cef9425)

![cc3](https://github.com/user-attachments/assets/53f44315-ece7-4b73-9cbb-1da2493838c8)

![cc4](https://github.com/user-attachments/assets/99a6bb19-f155-4e04-8034-35b2807a9ed1)


- Users Management: Admin can view citizen accounts, check report history, and manage user-related information to maintain a transparent and secure environment.

![u1](https://github.com/user-attachments/assets/b1b7035e-49f7-4f32-9782-d8376ac7dc4e)

## Database Table:

```
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `firstName` varchar(50) NOT NULL,
  `lastName` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','citizen') DEFAULT 'citizen',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
);

CREATE TABLE `assets` (
  `Asset_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Asset_Type` varchar(50) NOT NULL,
  `Asset_Name` varchar(255) NOT NULL,
  `Asset_Condition` enum('Good','Fair','Poor','New Construction','Needs Maintenance','Under Maintenance') NOT NULL,
  `Expenses` decimal(15,2) unsigned NOT NULL,
  `Location` text NOT NULL,
  `Installation_Date` date NOT NULL,
  `Maintenance_Interval` int unsigned NOT NULL,
  `Checking_Date` date DEFAULT NULL,
  `Last_Maintenance_Date` date DEFAULT NULL,
  `Created_At` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`Asset_ID`)
);

CREATE TABLE `projects` (
  `Project_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Project_Name` varchar(255) NOT NULL,
  `Description` text DEFAULT NULL,
  `Budget` decimal(15,2) unsigned DEFAULT 0.00,
  `Project_Type` enum('Large','Routine','Urgent') NOT NULL,
  `Project_Director` varchar(100) DEFAULT NULL,
  `Start_Date` date DEFAULT NULL,
  `End_Date` date DEFAULT NULL,
  `Status` enum('Not Started','Active','Paused','Completed','Cancelled') NOT NULL DEFAULT 'Not Started',
  `Created_At` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`Project_ID`)
);

CREATE TABLE `packages` (
  `Package_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Project_ID` int unsigned NOT NULL,
  `Package_Name` varchar(255) NOT NULL,
  `Description` text DEFAULT NULL,
  `Package_Type` enum('DPP','Maintenance') NOT NULL,
  `Team_Leader` varchar(100) DEFAULT NULL,
  `Budget` decimal(15,2) unsigned DEFAULT NULL,
  `Start_Date` date DEFAULT NULL,
  `End_Date` date DEFAULT NULL,
  `Status` enum('Not Started','Active','Paused','Completed','Cancelled') NOT NULL DEFAULT 'Not Started',
  `Created_At` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`Package_ID`),
  FOREIGN KEY (`Project_ID`) REFERENCES `projects` (`Project_ID`) ON DELETE CASCADE
);

CREATE TABLE `zones` (
  `Zone_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Zone_Name` mediumtext DEFAULT NULL,
  `Zone_Code` varchar(20) NOT NULL,
  `Created_At` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`Zone_ID`)
);

CREATE TABLE `project_zones` (
  `Project_Zone_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Project_ID` int unsigned NOT NULL,
  `Zone_ID` int unsigned NOT NULL,
  PRIMARY KEY (`Project_Zone_ID`),
  FOREIGN KEY (`Project_ID`) REFERENCES `projects` (`Project_ID`) ON DELETE CASCADE,
  FOREIGN KEY (`Zone_ID`) REFERENCES `zones` (`Zone_ID`) ON DELETE CASCADE
);

CREATE TABLE `citizen_reports` (
  `Report_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Report_type` varchar(100) DEFAULT NULL,
  `Report_Date` date DEFAULT NULL,
  `Description` text DEFAULT NULL,
  `Report_Image` longblob DEFAULT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `Status` enum('Submitted','Under Review','In Progress','Resolved','Closed') DEFAULT 'Submitted',
  `Admin_Response` text DEFAULT NULL,
  `Response_Date` date DEFAULT NULL,
  `Asset_ID` int unsigned DEFAULT NULL,
  `Project_ID` int unsigned DEFAULT NULL,
  PRIMARY KEY (`Report_ID`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  FOREIGN KEY (`Asset_ID`) REFERENCES `assets` (`Asset_ID`) ON DELETE SET NULL,
  FOREIGN KEY (`Project_ID`) REFERENCES `projects` (`Project_ID`) ON DELETE SET NULL
);

CREATE TABLE `citizens` (
  `Citizen_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Citizen_name` varchar(100) DEFAULT NULL,
  `Address` text DEFAULT NULL,
  `Contact` varchar(20) DEFAULT NULL,
  `Report_ID` int unsigned DEFAULT NULL,
  `user_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`Citizen_ID`),
  FOREIGN KEY (`Report_ID`) REFERENCES `citizen_reports` (`Report_ID`) ON DELETE SET NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

CREATE TABLE `maintenance` (
  `Maintenance_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `task_name` varchar(255) NOT NULL,
  `Task_type` enum('Construction','Repair','Maintenance','Restoration') NOT NULL,
  `Asset_ID` int unsigned NOT NULL,
  `Cost` decimal(15,2) unsigned NOT NULL,
  `Start_Date` date NOT NULL,
  `End_Date` date DEFAULT NULL,
  `Description` text DEFAULT NULL,
  `Status` enum('Not Started','Active','Paused','Completed','Cancelled') NOT NULL DEFAULT 'Not Started',
  `Created_At` timestamp NOT NULL DEFAULT current_timestamp(),
  `Report_ID` int unsigned DEFAULT NULL,
  `Project_ID` int unsigned DEFAULT NULL,
  `Package_ID` int unsigned DEFAULT NULL,
  `Zone_ID` int unsigned DEFAULT NULL,
  PRIMARY KEY (`Maintenance_ID`),
  FOREIGN KEY (`Asset_ID`) REFERENCES `assets` (`Asset_ID`) ON DELETE CASCADE,
  FOREIGN KEY (`Report_ID`) REFERENCES `citizen_reports` (`Report_ID`) ON DELETE SET NULL,
  FOREIGN KEY (`Project_ID`) REFERENCES `projects` (`Project_ID`) ON DELETE SET NULL,
  FOREIGN KEY (`Package_ID`) REFERENCES `packages` (`Package_ID`) ON DELETE SET NULL,
  FOREIGN KEY (`Zone_ID`) REFERENCES `zones` (`Zone_ID`) ON DELETE SET NULL
);

CREATE TABLE `budgets` (
  `Budget_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Amount_Allocation` decimal(15,2) unsigned DEFAULT NULL,
  `Amount_Spent` decimal(15,2) unsigned DEFAULT NULL,
  `Asset_ID` int unsigned DEFAULT NULL,
  `Project_ID` int unsigned DEFAULT NULL,
  `Package_ID` int unsigned DEFAULT NULL,
  PRIMARY KEY (`Budget_ID`),
  FOREIGN KEY (`Asset_ID`) REFERENCES `assets` (`Asset_ID`) ON DELETE SET NULL,
  FOREIGN KEY (`Project_ID`) REFERENCES `projects` (`Project_ID`) ON DELETE SET NULL,
  FOREIGN KEY (`Package_ID`) REFERENCES `packages` (`Package_ID`) ON DELETE SET NULL
);

CREATE TABLE `workers` (
  `Worker_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Worker_Name` varchar(100) NOT NULL,
  `Worker_Salary` decimal(15,2) unsigned NOT NULL,
  `Contact` varchar(20) DEFAULT NULL,
  `Designation` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`Worker_ID`)
);

CREATE TABLE `worker_assignments` (
  `Assignment_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Worker_ID` int unsigned NOT NULL,
  `Assignment_Type` enum('project','package','maintenance') NOT NULL,
  `Project_ID` int unsigned DEFAULT NULL,
  `Package_ID` int unsigned DEFAULT NULL,
  `Maintenance_ID` int unsigned DEFAULT NULL,
  `Assigned_Date` datetime DEFAULT current_timestamp(),
  `Assigned_By` varchar(100) DEFAULT NULL,
  `Role` varchar(50) DEFAULT NULL,
  `Designation` varchar(100) DEFAULT NULL,
  `Status` enum('Active','Paused','Completed','Fired') NOT NULL DEFAULT 'Active',
  `Notes` text DEFAULT NULL,
  PRIMARY KEY (`Assignment_ID`),
  FOREIGN KEY (`Worker_ID`) REFERENCES `workers` (`Worker_ID`) ON DELETE CASCADE,
  FOREIGN KEY (`Project_ID`) REFERENCES `projects` (`Project_ID`) ON DELETE SET NULL,
  FOREIGN KEY (`Package_ID`) REFERENCES `packages` (`Package_ID`) ON DELETE SET NULL,
  FOREIGN KEY (`Maintenance_ID`) REFERENCES `maintenance` (`Maintenance_ID`) ON DELETE SET NULL
);

CREATE TABLE `resources` (
  `Resource_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Resource_Type` varchar(100) DEFAULT NULL,
  `Quantity` int unsigned NOT NULL,
  `Unit_Cost` decimal(10,2) unsigned DEFAULT NULL,
  `Maintenance_ID` int unsigned DEFAULT NULL,
  PRIMARY KEY (`Resource_ID`),
  FOREIGN KEY (`Maintenance_ID`) REFERENCES `maintenance` (`Maintenance_ID`) ON DELETE SET NULL
);

CREATE TABLE `report_images` (
  `Image_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Report_ID` int unsigned DEFAULT NULL,
  `Maintenance_ID` int unsigned DEFAULT NULL,
  `Image_Path` varchar(500) NOT NULL,
  `Uploaded_By` int unsigned DEFAULT NULL,
  `Upload_Date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`Image_ID`),
  FOREIGN KEY (`Report_ID`) REFERENCES `citizen_reports` (`Report_ID`) ON DELETE CASCADE,
  FOREIGN KEY (`Maintenance_ID`) REFERENCES `maintenance` (`Maintenance_ID`) ON DELETE CASCADE,
  FOREIGN KEY (`Uploaded_By`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
```

## Tool Used:
- Frontend: HTML, CSS, JavaScript & Bootstrap
- Backend: PHP
- Database: MySQL


## Contact:
- Email: afsanahena24@gmail.com

- LinkedIn: https://www.linkedin.com/in/afsana-hena/
