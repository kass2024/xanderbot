<?php
/**
 * Comprehensive FAQ Seeder for Xander Chatbot
 * 
 * This script adds comprehensive FAQ data to ensure the chatbot
 * always has relevant answers before falling back to AI
 */

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "<h2>📚 Seeding Comprehensive FAQ Database</h2>\n";

// Clear existing FAQs
DB::table('knowledge_bases')->delete();
echo "<p>🗑️ Cleared existing FAQ entries</p>\n";

$comprehensiveFaqs = [
    [
        'client_id' => 1,
        'question' => 'What services do you offer?',
        'answer' => 'Xander Global Scholars offers comprehensive visa and education consultancy services including: student visa applications, work visa assistance, study abroad program guidance, university admissions help, scholarship application support, document preparation, and pre-departure orientation.',
        'category' => 'Services',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'How do I apply for a student visa?',
        'answer' => 'To apply for a student visa: 1) Get acceptance letter from educational institution, 2) Prepare financial documents showing proof of funds, 3) Complete visa application forms, 4) Attend medical examination, 5) Gather required documents (passport, photos, transcripts), 6) Submit application and pay fees, 7) Attend visa interview if required. Our consultants guide you through each step.',
        'category' => 'Student Visa',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'What are the requirements for Canada student visa?',
        'answer' => 'Canada student visa requirements: 1) Letter of acceptance from Designated Learning Institution (DLI), 2) Proof of financial support ($10,000 per year plus tuition fees), 3) Valid passport, 4) Medical examination from approved panel physician, 5) Statement of purpose, 6) English/French proficiency test (IELTS/TOEFL), 7) Photographs meeting specifications, 8) Completed application forms (IMM 1294).',
        'category' => 'Canada',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'How much does your service cost?',
        'answer' => 'Our service fees vary based on destination country and visa type. We offer competitive pricing with flexible payment options. Initial consultation is completely free. Contact us with your specific requirements for a detailed quote. We also have package deals for multiple services.',
        'category' => 'Pricing',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'Do you help with scholarships?',
        'answer' => 'Yes! We provide comprehensive scholarship assistance: identify suitable scholarships based on your profile, help with application preparation, review essays and personal statements, assist with recommendation letters, and guide you through submission deadlines. We maintain an updated database of available scholarships for various countries and programs.',
        'category' => 'Scholarships',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'What countries do you help with?',
        'answer' => 'We assist with study abroad and visa applications for multiple countries including: Canada, USA, UK, Australia, New Zealand, Germany, France, Netherlands, Ireland, Singapore, and many European countries. Each country has specific requirements that our experts are familiar with.',
        'category' => 'Countries',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'How long does the visa process take?',
        'answer' => 'Visa processing times vary by country: Canada: 4-12 weeks, USA: 3-8 weeks, UK: 3-12 weeks, Australia: 4-16 weeks, European countries: 2-8 weeks. Processing times depend on application volume, time of year, and individual circumstances. We provide current estimates and help expedite where possible.',
        'category' => 'Processing Time',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'What documents do I need?',
        'answer' => 'Required documents typically include: valid passport, recent photographs, birth certificate, academic transcripts, degree certificates, financial statements, bank statements, sponsorship letters, medical certificates, police clearance certificate, English language test scores, and completed application forms. Requirements vary by country and visa type.',
        'category' => 'Documents',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'Can you help with university applications?',
        'answer' => 'Absolutely! We provide complete university application assistance: help select suitable universities and programs, prepare and review application documents, assist with personal statements and essays, guide through online application portals, track application deadlines, and communicate with universities on your behalf.',
        'category' => 'University Applications',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'What is your success rate?',
        'answer' => 'We maintain a high success rate of over 85% for student visa applications across all countries. Our success comes from thorough document preparation, proper application guidance, interview preparation, and staying updated with changing immigration policies. We can provide specific success rates for your target country.',
        'category' => 'Success Rate',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'Do you provide IELTS preparation?',
        'answer' => 'Yes, we offer IELTS preparation services including: practice tests, study materials, tips for each section (reading, writing, listening, speaking), mock interviews, and strategies to improve your score. We also help with TOEFL, PTE, and other English proficiency tests based on your requirements.',
        'category' => 'Language Tests',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'How do I get started?',
        'answer' => 'Getting started is easy: 1) Contact us for a free consultation, 2) Discuss your study abroad goals and preferences, 3) Our consultants assess your profile, 4) Receive personalized recommendations, 5) Choose your service package, 6) Begin the application process with our guidance. We\'re here to help at every step!',
        'category' => 'Getting Started',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'What if my visa is rejected?',
        'answer' => 'If your visa is rejected, we help analyze the rejection reasons, identify weak points in the application, prepare stronger documentation, address visa officer concerns, and guide you through reapplication. Our reapplication success rate is significantly higher due to our thorough analysis and improved preparation.',
        'category' => 'Visa Rejection',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'Do you offer work visa services?',
        'answer' => 'Yes, we provide work visa assistance for various countries including Canada, UK, Australia, and European nations. Services include job search guidance, employer applications, work permit applications, and immigration pathways. We help both skilled workers and those seeking employment opportunities abroad.',
        'category' => 'Work Visa',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'client_id' => 1,
        'question' => 'What are your office locations?',
        'answer' => 'Xander Global Scholars has offices in multiple locations to serve you better. We have physical offices and representatives in major cities. Contact us to find the nearest office or schedule a virtual consultation. We also serve international clients through our online consultation services.',
        'category' => 'Contact',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ],
];

try {
    DB::table('knowledge_bases')->insert($comprehensiveFaqs);
    echo "<p style='color: green;'>✅ Successfully added " . count($comprehensiveFaqs) . " comprehensive FAQ entries</p>\n";
    
    echo "<h3>📊 FAQ Categories Added:</h3>\n";
    echo "<ul>\n";
    echo "<li>Services - General service information</li>\n";
    echo "<li>Student Visa - Application guidance and requirements</li>\n";
    echo "<li>Canada - Canada-specific information</li>\n";
    echo "<li>Pricing - Cost and payment information</li>\n";
    echo "<li>Scholarships - Financial aid opportunities</li>\n";
    echo "<li>Countries - Destination country information</li>\n";
    echo "<li>Processing Time - Timeline information</li>\n";
    echo "<li>Documents - Required documentation</li>\n";
    echo "<li>University Applications - Academic guidance</li>\n";
    echo "<li>Success Rate - Performance metrics</li>\n";
    echo "<li>Language Tests - IELTS/TOEFL preparation</li>\n";
    echo "<li>Getting Started - Initial consultation process</li>\n";
    echo "<li>Visa Rejection - Rejection handling</li>\n";
    echo "<li>Work Visa - Employment opportunities</li>\n";
    echo "<li>Contact - Office and contact information</li>\n";
    echo "</ul>\n";
    
} catch (\Exception $e) {
    echo "<p style='color: red;'>❌ Error inserting FAQs: " . $e->getMessage() . "</p>\n";
}

echo "<hr>\n";
echo "<h3>🎯 Next Steps:</h3>\n";
echo "<ol>\n";
echo "<li>Clear Laravel cache: <code>php artisan cache:clear</code></li>\n";
echo "<li>Clear config cache: <code>php artisan config:clear</code></li>\n";
echo "<li>Test the chatbot with various questions</li>\n";
echo "<li>The bot should now find FAQ matches for common questions</li>\n";
echo "</ol>\n";

?>
