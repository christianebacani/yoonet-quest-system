-- ========================================
-- Comprehensive Skills Database Setup
-- ========================================
-- This creates hundreds of skills across multiple categories for the quest system
-- Each skill has tier definitions for assessment purposes

-- Clear existing data for fresh setup
DELETE FROM predefined_skills;
DELETE FROM skill_categories;

-- Insert comprehensive skill categories
INSERT INTO skill_categories (id, category_name, category_icon, display_order) VALUES
(1, 'Technical Skills', 'fas fa-code', 1),
(2, 'Communication Skills', 'fas fa-comments', 2),
(3, 'Soft Skills', 'fas fa-heart', 3),
(4, 'Business Skills', 'fas fa-briefcase', 4),
(5, 'Design & Creative', 'fas fa-palette', 5),
(6, 'Data & Analytics', 'fas fa-chart-bar', 6);

-- Insert Technical Skills (Programming, Development, DevOps, etc.)
INSERT INTO predefined_skills (skill_name, category_id, description) VALUES
-- Programming Languages
('JavaScript', 1, 'Frontend and backend JavaScript development'),
('Python', 1, 'Python programming for various applications'),
('Java', 1, 'Enterprise Java development'),
('C#', 1, 'Microsoft .NET framework development'),
('PHP', 1, 'Server-side web development with PHP'),
('TypeScript', 1, 'Strongly typed JavaScript development'),
('Go', 1, 'Google Go programming language'),
('Rust', 1, 'Systems programming with Rust'),
('C++', 1, 'High-performance systems programming'),
('C', 1, 'Low-level systems programming'),
('Ruby', 1, 'Ruby programming and Rails framework'),
('Swift', 1, 'iOS and macOS development'),
('Kotlin', 1, 'Android and cross-platform development'),
('Dart', 1, 'Flutter and web development'),
('R', 1, 'Statistical computing and data analysis'),
('MATLAB', 1, 'Mathematical computing and analysis'),
('Scala', 1, 'Functional and object-oriented programming'),
('Clojure', 1, 'Functional programming on JVM'),
('Haskell', 1, 'Pure functional programming'),
('Elixir', 1, 'Concurrent and fault-tolerant programming'),

-- Web Development
('HTML5', 1, 'Modern web markup language'),
('CSS3', 1, 'Advanced web styling and animations'),
('React.js', 1, 'Frontend JavaScript library'),
('Vue.js', 1, 'Progressive JavaScript framework'),
('Angular', 1, 'Full-featured frontend framework'),
('Node.js', 1, 'Server-side JavaScript runtime'),
('Express.js', 1, 'Node.js web application framework'),
('Next.js', 1, 'React-based full-stack framework'),
('Nuxt.js', 1, 'Vue.js-based full-stack framework'),
('Svelte', 1, 'Compile-time frontend framework'),
('Django', 1, 'Python web framework'),
('Flask', 1, 'Lightweight Python web framework'),
('Laravel', 1, 'PHP web application framework'),
('Spring Boot', 1, 'Java-based enterprise framework'),
('ASP.NET Core', 1, 'Microsoft web development framework'),
('Ruby on Rails', 1, 'Ruby web application framework'),

-- Mobile Development
('React Native', 1, 'Cross-platform mobile development'),
('Flutter', 1, 'Cross-platform UI toolkit'),
('iOS Development', 1, 'Native iPhone and iPad applications'),
('Android Development', 1, 'Native Android applications'),
('Xamarin', 1, 'Microsoft cross-platform mobile development'),
('Ionic', 1, 'Hybrid mobile app development'),
('Progressive Web Apps', 1, 'Web-based mobile experiences'),

-- Database Technologies
('MySQL', 1, 'Relational database management'),
('PostgreSQL', 1, 'Advanced relational database'),
('MongoDB', 1, 'NoSQL document database'),
('Redis', 1, 'In-memory data structure store'),
('Elasticsearch', 1, 'Search and analytics engine'),
('Oracle Database', 1, 'Enterprise database management'),
('SQL Server', 1, 'Microsoft database platform'),
('Cassandra', 1, 'Distributed NoSQL database'),
('Neo4j', 1, 'Graph database management'),
('DynamoDB', 1, 'AWS NoSQL database'),

-- Cloud & DevOps
('AWS', 1, 'Amazon Web Services cloud platform'),
('Azure', 1, 'Microsoft Azure cloud services'),
('Google Cloud Platform', 1, 'Google cloud computing services'),
('Docker', 1, 'Containerization technology'),
('Kubernetes', 1, 'Container orchestration platform'),
('Jenkins', 1, 'Continuous integration/deployment'),
('GitLab CI', 1, 'GitLab continuous integration'),
('GitHub Actions', 1, 'GitHub workflow automation'),
('Terraform', 1, 'Infrastructure as code'),
('Ansible', 1, 'Configuration management'),
('Chef', 1, 'Infrastructure automation'),
('Puppet', 1, 'Configuration management'),
('Vagrant', 1, 'Development environment management'),
('Nginx', 1, 'Web server and reverse proxy'),
('Apache HTTP Server', 1, 'Web server software'),

-- Version Control & Tools
('Git', 1, 'Distributed version control system'),
('GitHub', 1, 'Git repository hosting service'),
('GitLab', 1, 'Git repository and DevOps platform'),
('Bitbucket', 1, 'Git repository management'),
('SVN', 1, 'Centralized version control'),
('Mercurial', 1, 'Distributed version control'),

-- Testing & Quality Assurance
('Unit Testing', 1, 'Code unit testing practices'),
('Integration Testing', 1, 'System integration testing'),
('End-to-End Testing', 1, 'Complete workflow testing'),
('Test-Driven Development', 1, 'TDD methodology'),
('Behavior-Driven Development', 1, 'BDD testing approach'),
('Jest', 1, 'JavaScript testing framework'),
('Selenium', 1, 'Web application testing'),
('Cypress', 1, 'End-to-end testing framework'),
('JUnit', 1, 'Java unit testing framework'),
('PyTest', 1, 'Python testing framework'),
('Postman', 1, 'API testing and development'),

-- Security
('Cybersecurity', 1, 'Information security practices'),
('Penetration Testing', 1, 'Security vulnerability assessment'),
('OAuth', 1, 'Authentication and authorization'),
('JWT', 1, 'JSON Web Token implementation'),
('SSL/TLS', 1, 'Transport Layer Security'),
('Encryption', 1, 'Data encryption and decryption'),
('OWASP', 1, 'Web application security practices'),

-- AI/ML
('Machine Learning', 1, 'ML algorithms and implementation'),
('Deep Learning', 1, 'Neural networks and deep learning'),
('TensorFlow', 1, 'Google ML framework'),
('PyTorch', 1, 'Facebook ML framework'),
('scikit-learn', 1, 'Python ML library'),
('Natural Language Processing', 1, 'Text processing and analysis'),
('Computer Vision', 1, 'Image and video analysis'),
('Reinforcement Learning', 1, 'RL algorithms and applications'),

-- Communication Skills
('Written Communication', 2, 'Clear and effective writing skills'),
('Verbal Communication', 2, 'Spoken communication and articulation'),
('Public Speaking', 2, 'Presentation and speaking to audiences'),
('Technical Writing', 2, 'Documentation and technical communication'),
('Business Writing', 2, 'Professional correspondence and reports'),
('Email Communication', 2, 'Professional email correspondence'),
('Meeting Facilitation', 2, 'Leading and managing meetings'),
('Active Listening', 2, 'Engaged and empathetic listening'),
('Conflict Resolution', 2, 'Managing and resolving disputes'),
('Negotiation', 2, 'Bargaining and agreement reaching'),
('Cross-Cultural Communication', 2, 'Communication across cultures'),
('Storytelling', 2, 'Narrative communication techniques'),
('Presentation Skills', 2, 'Creating and delivering presentations'),
('Interview Skills', 2, 'Conducting and participating in interviews'),
('Customer Communication', 2, 'Client and customer interaction'),
('Team Communication', 2, 'Collaborative team interaction'),
('Remote Communication', 2, 'Virtual and remote collaboration'),
('Social Media Communication', 2, 'Professional social media presence'),
('Feedback Delivery', 2, 'Providing constructive feedback'),
('Feedback Reception', 2, 'Receiving and acting on feedback'),

-- Soft Skills
('Leadership', 3, 'Leading and inspiring others'),
('Team Collaboration', 3, 'Working effectively with others'),
('Problem Solving', 3, 'Analytical and creative problem resolution'),
('Critical Thinking', 3, 'Objective analysis and evaluation'),
('Creativity', 3, 'Innovation and creative thinking'),
('Adaptability', 3, 'Flexibility and change management'),
('Time Management', 3, 'Efficient use of time and prioritization'),
('Stress Management', 3, 'Managing pressure and stress'),
('Emotional Intelligence', 3, 'Understanding and managing emotions'),
('Empathy', 3, 'Understanding others perspectives'),
('Decision Making', 3, 'Making informed and timely decisions'),
('Self-Motivation', 3, 'Internal drive and motivation'),
('Resilience', 3, 'Bouncing back from setbacks'),
('Attention to Detail', 3, 'Accuracy and thoroughness'),
('Organization', 3, 'Structure and systematic approaches'),
('Mentoring', 3, 'Coaching and developing others'),
('Conflict Management', 3, 'Managing interpersonal conflicts'),
('Cultural Sensitivity', 3, 'Awareness of cultural differences'),
('Work-Life Balance', 3, 'Managing personal and professional life'),
('Continuous Learning', 3, 'Commitment to ongoing development'),
('Accountability', 3, 'Taking responsibility for actions'),
('Initiative', 3, 'Proactive action and self-starting'),
('Patience', 3, 'Remaining calm under pressure'),
('Networking', 3, 'Building professional relationships'),
('Delegation', 3, 'Effectively assigning tasks'),

-- Business Skills
('Project Management', 4, 'Planning and executing projects'),
('Agile Methodology', 4, 'Agile project management practices'),
('Scrum Master', 4, 'Scrum framework leadership'),
('Product Management', 4, 'Product lifecycle management'),
('Business Analysis', 4, 'Analyzing business requirements'),
('Market Research', 4, 'Market analysis and research'),
('Financial Analysis', 4, 'Financial data analysis'),
('Budgeting', 4, 'Financial planning and budgeting'),
('Cost Management', 4, 'Managing and controlling costs'),
('Risk Management', 4, 'Identifying and managing risks'),
('Strategic Planning', 4, 'Long-term business planning'),
('Operations Management', 4, 'Managing business operations'),
('Supply Chain Management', 4, 'Managing supply chains'),
('Quality Management', 4, 'Ensuring quality standards'),
('Customer Relationship Management', 4, 'Managing customer relationships'),
('Sales', 4, 'Selling products and services'),
('Marketing', 4, 'Promoting products and services'),
('Digital Marketing', 4, 'Online marketing strategies'),
('Content Marketing', 4, 'Content-based marketing approaches'),
('Social Media Marketing', 4, 'Social media promotion'),
('SEO/SEM', 4, 'Search engine optimization/marketing'),
('Brand Management', 4, 'Managing brand identity'),
('Entrepreneurship', 4, 'Starting and running businesses'),
('Innovation Management', 4, 'Managing innovation processes'),
('Change Management', 4, 'Managing organizational change'),
('Performance Management', 4, 'Managing team and individual performance'),
('Recruitment', 4, 'Hiring and talent acquisition'),
('Training & Development', 4, 'Employee skill development'),
('Legal Compliance', 4, 'Understanding legal requirements'),
('Contract Management', 4, 'Managing business contracts'),

-- Design & Creative Skills
('UI/UX Design', 5, 'User interface and experience design'),
('Graphic Design', 5, 'Visual design and graphics'),
('Web Design', 5, 'Website design and layout'),
('Logo Design', 5, 'Brand identity and logo creation'),
('Illustration', 5, 'Digital and traditional illustration'),
('Photography', 5, 'Digital photography and editing'),
('Video Production', 5, 'Video creation and editing'),
('Animation', 5, 'Motion graphics and animation'),
('3D Modeling', 5, '3D design and modeling'),
('Adobe Photoshop', 5, 'Image editing and manipulation'),
('Adobe Illustrator', 5, 'Vector graphics design'),
('Adobe InDesign', 5, 'Layout and publishing design'),
('Figma', 5, 'Collaborative design tool'),
('Sketch', 5, 'Digital design platform'),
('Canva', 5, 'Graphic design platform'),
('Typography', 5, 'Font design and text layout'),
('Color Theory', 5, 'Understanding color in design'),
('Brand Identity', 5, 'Creating cohesive brand visuals'),
('Print Design', 5, 'Design for printed materials'),
('Packaging Design', 5, 'Product packaging design'),

-- Data & Analytics Skills
('Data Analysis', 6, 'Statistical analysis and insights'),
('Data Visualization', 6, 'Creating visual data representations'),
('Business Intelligence', 6, 'BI tools and reporting'),
('SQL', 6, 'Database query language'),
('Excel Advanced', 6, 'Advanced spreadsheet analysis'),
('Power BI', 6, 'Microsoft business intelligence'),
('Tableau', 6, 'Data visualization platform'),
('Google Analytics', 6, 'Web analytics platform'),
('Statistical Analysis', 6, 'Statistical methods and techniques'),
('Predictive Analytics', 6, 'Forecasting and prediction'),
('A/B Testing', 6, 'Experimental design and testing'),
('Data Mining', 6, 'Extracting insights from large datasets'),
('ETL Processes', 6, 'Extract, Transform, Load operations'),
('Data Warehousing', 6, 'Data storage and management'),
('Big Data', 6, 'Large-scale data processing'),
('Apache Spark', 6, 'Big data processing framework'),
('Hadoop', 6, 'Distributed storage and processing'),
('Pandas', 6, 'Python data manipulation library'),
('NumPy', 6, 'Python numerical computing'),
('Matplotlib', 6, 'Python plotting library');

-- Create skill tiers reference table
CREATE TABLE IF NOT EXISTS skill_tiers (
    tier_level INT PRIMARY KEY,
    tier_name VARCHAR(20) NOT NULL,
    base_points INT NOT NULL,
    description TEXT
);

INSERT INTO skill_tiers (tier_level, tier_name, base_points, description) VALUES
(1, 'T1 - Beginner', 25, 'Basic understanding and application'),
(2, 'T2 - Intermediate', 40, 'Solid competency with guided application'),
(3, 'T3 - Advanced', 60, 'Independent application and problem-solving'),
(4, 'T4 - Expert', 85, 'Mastery with ability to teach others'),
(5, 'T5 - Thought Leader', 120, 'Innovation and industry leadership');

-- Create performance modifiers reference
CREATE TABLE IF NOT EXISTS performance_modifiers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    performance_level VARCHAR(30) NOT NULL,
    modifier_percentage INT NOT NULL,
    description TEXT
);

INSERT INTO performance_modifiers (performance_level, modifier_percentage, description) VALUES
('Below Expectations', -30, 'Does not meet the required standard'),
('Meets Expectations', 0, 'Satisfactory performance as expected'),
('Exceeds Expectations', 25, 'Performance above the expected level'),
('Exceptional', 50, 'Outstanding performance far exceeding expectations');

SELECT 'Comprehensive skills database created successfully!' as status;
SELECT COUNT(*) as total_skills, category_id, 
       (SELECT category_name FROM skill_categories WHERE id = predefined_skills.category_id) as category_name
FROM predefined_skills 
GROUP BY category_id;