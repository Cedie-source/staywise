<?php
session_start();
require_once '../config/db.php';

// Check if user is tenant
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tenant') {
    header("Location: ../index.php");
    exit();
}

// Define base URL for API calls
$base_url = '/StayWise/';

$page_title = "AI Assistant";
include '../includes/header.php';
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">
                        <i class="fas fa-robot me-2"></i>AI Assistant - StayWise Helper
                    </h4>
                    <small class="text-muted">Get instant answers to common questions</small>
                </div>
                <div class="card-body">
                    <div class="chatbot-container tenant-ui">
                        <div class="chatbot-header d-flex align-items-center justify-content-between">
                            <div><i class="fas fa-robot me-2"></i>AI Assistant</div>
                            <span class="badge bg-success ms-2">Online</span>
                        </div>

                        <div class="chatbot-body" id="chat-body">
                            <div id="chat-messages" class="chat-messages"></div>
                        </div>

                        <div class="chatbot-footer">
                            <form id="chat-form" class="input-group" onsubmit="return false;">
                                <input type="text" class="form-control" id="chat-input" placeholder="Ask your question here...">
                                <button class="btn btn-primary" type="submit" id="chat-send">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </form>
                            <small class="text-muted mt-2 d-block" id="chat-hint">
                                <i class="fas fa-info-circle me-1"></i>
                                Ask about payments, maintenance, or building policies. Don’t share sensitive info.
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FAQ Section -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-question-circle me-2"></i>Frequently Asked Questions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="accordion" id="faqAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="faq1">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse1">
                                    How do I submit a rent payment?
                                </button>
                            </h2>
                            <div id="collapse1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Go to the Payments section and click "Upload Payment Proof". Upload your payment receipt or bank transfer confirmation, and our admin will verify it within 24 hours.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="faq2">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse2">
                                    How do I report a maintenance issue?
                                </button>
                            </h2>
                            <div id="collapse2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Visit the Complaints section and click "Submit New Complaint". Provide a detailed description of the issue, and our maintenance team will respond promptly.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="faq3">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse3">
                                    When is rent due each month?
                                </button>
                            </h2>
                            <div id="collapse3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Rent is typically due on the 1st of each month. Late fees may apply after the 5th. Check your lease agreement for specific terms.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="faq4">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse4">
                                    How can I contact the property manager?
                                </button>
                            </h2>
                            <div id="collapse4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    You can submit complaints through this system, call our office during business hours, or send an email. For emergencies, use the emergency contact number provided in your lease.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Integration Guide -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-code me-2"></i>AI Integration Guide
                    </h5>
                </div>
                <div class="card-body">
                    <p>To integrate a real AI chatbot, you can:</p>
                    <ol>
                        <li><strong>OpenAI API:</strong> Use GPT models for natural language processing</li>
                        <li><strong>Dialogflow:</strong> Google's conversational AI platform</li>
                        <li><strong>Microsoft Bot Framework:</strong> Build intelligent bots</li>
                        <li><strong>Custom API:</strong> Create your own AI service</li>
                    </ol>
                    <p class="mb-0">
                        <small class="text-muted">
                            The chatbot interface is ready - just replace the placeholder with your chosen AI service integration.
                        </small>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
<script>
// Simple chat UI state
const messagesEl = document.getElementById('chat-messages');
const inputEl = document.getElementById('chat-input');
const formEl = document.getElementById('chat-form');
const sendBtn = document.getElementById('chat-send');
let history = [];

function addMessage(role, content, quickAction = null) {
    const wrapper = document.createElement('div');
    wrapper.className = 'chat-bubble ' + (role === 'user' ? 'from-user' : 'from-assistant');
    const inner = document.createElement('div');
    inner.className = 'bubble-inner';
    inner.innerHTML = content; // Use innerHTML to support HTML content
    wrapper.appendChild(inner);
    
    // Add quick action button if provided
    if (quickAction && role === 'assistant') {
        const actionBtn = document.createElement('a');
        actionBtn.href = quickAction.url;
        actionBtn.className = 'btn btn-sm btn-outline-primary mt-2';
        actionBtn.style.display = 'block';
        actionBtn.style.maxWidth = '120px';
        actionBtn.innerHTML = '<i class="fas fa-arrow-right me-1"></i>' + quickAction.label;
        wrapper.appendChild(actionBtn);
    }
    
    messagesEl.appendChild(wrapper);
    messagesEl.scrollTop = messagesEl.scrollHeight;
}

async function sendChatMessage() {
    const text = inputEl.value.trim();
    if (!text) return;
    inputEl.value = '';
    addMessage('user', text);
    sendBtn.disabled = true;
    inputEl.disabled = true;
    
    try {
        // Use enhanced API for better intent routing
        const res = await fetch('<?php echo $base_url; ?>api/chat_enhanced.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: text, history })
        });
        
        const data = await res.json();
        if (!res.ok || data.error) {
            throw new Error(data.error || 'Request failed');
        }
        
        const reply = data.reply || 'Sorry, I could not generate a response.';
        addMessage('assistant', reply, data.quick_action);
        
        // Update history
        history.push({ role: 'user', content: text });
        history.push({ role: 'assistant', content: reply });
        
        // Log intent detection (for debugging)
        if (data.intent) {
            console.log('Detected intent:', data.intent, '(confidence:', data.confidence + ')');
        }
    } catch (err) {
        addMessage('assistant', '<strong>Error:</strong> Unable to reach the AI assistant. Check that your Groq API key is configured. <br><small>Get free key: <a href="https://console.groq.com" target="_blank">console.groq.com</a></small>');
        console.error(err);
    } finally {
        sendBtn.disabled = false;
        inputEl.disabled = false;
        inputEl.focus();
    }
}

formEl.addEventListener('submit', (e) => { e.preventDefault(); sendChatMessage(); });
</script>