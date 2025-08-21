<template>
  <div v-if="showModal" class="privy-modal-overlay" @click="handleOverlayClick">
    <div class="privy-modal" @click.stop>
      <button class="close-button" @click="closeModal">×</button>
      
      <!-- Step 1: Email input -->
      <div v-if="step === 'email'" class="privy-modal-content">
        
        <div class="modal-header">
          <img src="/assets/monad-logo-black.webp" alt="Monad" class="modal-logo" />
          <h2>Sign in with Monad Games ID</h2>
          <p>Enter your email to continue</p>
        </div>
        
        <div class="email-form">
          <input
            v-model="email"
            type="email"
            placeholder="your@email.com"
            class="email-input"
            @keyup.enter="sendEmailCode"
          />
          <button @click="sendEmailCode" class="submit-button" :disabled="!email || loading">
            {{ loading ? 'Sending...' : 'Submit' }}
          </button>
        </div>
        
        <div v-if="error" class="error-message">{{ error }}</div>
        
        <div class="powered-by">
          Protected by <strong>Privy</strong>
        </div>
      </div>
      
      <!-- Step 2: OTP verification -->
      <div v-else-if="step === 'verify'" class="privy-modal-content">
        <button @click="step = 'email'" class="back-button">←</button>
        
        <div class="modal-header">
          <span class="email-icon">✉️</span>
          <h2>Enter confirmation code</h2>
          <p>Please check {{ email }} for an email from privy.io and enter your code below.</p>
        </div>
        
        <div class="otp-form">
          <div class="otp-inputs">
            <input
              v-for="(digit, index) in otpCode"
              :key="index"
              v-model="otpCode[index]"
              type="text"
              maxlength="1"
              class="otp-input"
              @input="handleOtpInput(index)"
              @keydown="handleOtpKeydown($event, index)"
              @paste="handleOtpPaste($event, index)"
            />
          </div>
          
          <button @click="verifyCode" class="submit-button" :disabled="!isOtpComplete || loading">
            {{ loading ? 'Verifying...' : 'Verify' }}
          </button>
        </div>
        
        <div v-if="error" class="error-message">{{ error }}</div>
        
        <button @click="resendCode" class="resend-button" :disabled="loading">
          Didn't get an email? <span>Resend code</span>
        </button>
      </div>
      
      <!-- Step 3: Success -->
      <div v-else-if="step === 'success'" class="privy-modal-content">
        <div class="modal-header">
          <span class="success-icon">✓</span>
          <h2>Welcome!</h2>
          <p>Authentication successful</p>
        </div>
        
        <button @click="closeModal" class="submit-button">
          Continue to Game
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { privyService } from '@/services/privy';

const props = defineProps({
  show: Boolean
});

const emit = defineEmits(['close', 'success']);

const showModal = ref(false);
const step = ref('email'); // email, verify, success
const email = ref('');
const otpCode = ref(['', '', '', '', '', '']);
const loading = ref(false);
const error = ref('');

watch(() => props.show, (newVal) => {
  showModal.value = newVal;
  if (newVal) {
    resetModal();
  }
});

const isOtpComplete = computed(() => {
  return otpCode.value.every(digit => digit !== '');
});

const resetModal = () => {
  step.value = 'email';
  email.value = '';
  otpCode.value = ['', '', '', '', '', ''];
  error.value = '';
  loading.value = false;
};

const closeModal = () => {
  showModal.value = false;
  emit('close');
  resetModal();
};

const handleOverlayClick = (e) => {
  if (e.target === e.currentTarget) {
    closeModal();
  }
};

const sendEmailCode = async () => {
  if (!email.value || loading.value) return;
  
  loading.value = true;
  error.value = '';
  
  try {
    const result = await privyService.handleEmailLogin(email.value);
    
    if (result.success) {
      step.value = 'verify';
    } else {
      error.value = result.error || 'Failed to send code';
    }
  } catch (err) {
    error.value = err.message || 'Failed to send code';
  } finally {
    loading.value = false;
  }
};

const verifyCode = async () => {
  if (!isOtpComplete.value || loading.value) return;
  
  loading.value = true;
  error.value = '';
  
  const code = otpCode.value.join('');
  
  try {
    const result = await privyService.verifyEmailCode(email.value, code);
    
    if (result.success) {
      step.value = 'success';
      setTimeout(() => {
        emit('success', result);
        closeModal();
      }, 1500);
    } else {
      error.value = result.error || 'Invalid code';
      otpCode.value = ['', '', '', '', '', ''];
    }
  } catch (err) {
    error.value = err.message || 'Verification failed';
  } finally {
    loading.value = false;
  }
};

const resendCode = () => {
  otpCode.value = ['', '', '', '', '', ''];
  sendEmailCode();
};

const handleOtpInput = (index) => {
  if (otpCode.value[index] && index < 5) {
    // Focus next input
    const nextInput = document.querySelector(`input[type="text"]:nth-of-type(${index + 2})`);
    if (nextInput) {
      nextInput.focus();
    }
  }
  
  // Auto-submit when all digits are entered
  if (isOtpComplete.value && !loading.value) {
    setTimeout(() => {
      verifyCode();
    }, 100); // Small delay to ensure UI updates
  }
};

const handleOtpKeydown = (event, index) => {
  if (event.key === 'Backspace' && !otpCode.value[index] && index > 0) {
    // Focus previous input on backspace
    const prevInput = document.querySelector(`input[type="text"]:nth-of-type(${index})`);
    if (prevInput) {
      prevInput.focus();
    }
  }
};

const handleOtpPaste = (event, index) => {
  event.preventDefault();
  
  // Get pasted text
  const pastedText = (event.clipboardData || window.clipboardData).getData('text');
  
  // Clean the pasted text (remove non-digits and limit to 6 characters)
  const digits = pastedText.replace(/\D/g, '').slice(0, 6);
  
  if (digits.length > 0) {
    // Fill the OTP inputs with the pasted digits
    for (let i = 0; i < 6; i++) {
      if (i < digits.length) {
        otpCode.value[i] = digits[i];
      } else {
        otpCode.value[i] = '';
      }
    }
    
    // Focus the next empty input or the last input if all are filled
    const nextEmptyIndex = digits.length < 6 ? digits.length : 5;
    const nextInput = document.querySelector(`input[type="text"]:nth-of-type(${nextEmptyIndex + 1})`);
    if (nextInput) {
      nextInput.focus();
    }
    
    // Auto-submit if complete code was pasted
    if (digits.length === 6 && !loading.value) {
      setTimeout(() => {
        verifyCode();
      }, 200); // Slightly longer delay for paste operations
    }
  }
};
</script>

<style scoped>
.privy-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: grid;
  place-items: center;
  z-index: 10000;
  padding: 20px;
}

.privy-modal {
  background: white;
  border-radius: 16px;
  width: 400px;
  max-width: calc(100vw - 40px);
  position: relative;
  filter: drop-shadow(0 10px 25px rgba(0, 0, 0, 0.15));
  overflow: hidden;
}

.close-button {
  position: absolute;
  top: 16px;
  right: 16px;
  background: transparent;
  border: none;
  font-size: 24px;
  color: #666;
  cursor: pointer;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: background 0.2s;
}

.close-button:hover {
  background: #f3f4f6;
}

.back-button {
  position: absolute;
  top: 16px;
  left: 16px;
  background: transparent;
  border: none;
  font-size: 20px;
  color: #666;
  cursor: pointer;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: background 0.2s;
}

.back-button:hover {
  background: #f3f4f6;
}

.privy-modal-content {
  padding: 40px 32px 32px !important;
  max-width: 100% !important;
  width: 100% !important;
  background: transparent !important;
  box-shadow: none !important;
  border: none !important;
  border-radius: 0 !important;
  color: inherit !important;
  display: block !important;
  flex-direction: unset !important;
  gap: unset !important;
}

.modal-header {
  text-align: center;
  margin-bottom: 32px;
}

.modal-logo {
  width: 60px;
  height: 60px;
  margin-bottom: 16px;
}

.modal-header h2 {
  font-size: 24px;
  font-weight: 600;
  color: #111 !important;
  margin: 0 0 8px;
}

.modal-header p {
  font-size: 14px;
  color: #666 !important;
  margin: 0;
}

.login-options {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 24px;
}

.login-button {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 14px 20px;
  border: 1px solid #e5e7eb;
  background: white;
  border-radius: 8px;
  font-size: 16px;
  font-weight: 500;
  color: #111;
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: none;
}

.login-button:hover {
  background: #f9fafb;
  border-color: #9333ea;
}

.button-icon {
  font-size: 20px;
}

.email-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-bottom: 24px;
}

.email-input {
  padding: 12px 16px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  font-size: 16px;
  transition: border-color 0.2s;
  background: white;
  color: #111;
}

.email-input:focus {
  outline: none;
  border-color: #9333ea;
}

.submit-button {
  padding: 12px 20px;
  background: #9333ea;
  color: white;
  border: none;
  border-radius: 8px;
  font-size: 16px;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.2s;
}

.submit-button:hover:not(:disabled) {
  background: #7c3aed;
}

.submit-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.otp-form {
  display: flex;
  flex-direction: column;
  gap: 24px;
  margin-bottom: 24px;
}

.otp-inputs {
  display: flex;
  gap: 8px;
  justify-content: center;
}

.otp-input {
  width: 48px;
  height: 48px;
  text-align: center;
  font-size: 20px;
  font-weight: 600;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  transition: border-color 0.2s;
  background: white;
  color: #111;
}

.otp-input:focus {
  outline: none;
  border-color: #9333ea;
}

.email-icon,
.success-icon {
  font-size: 48px;
  margin-bottom: 16px;
  display: block;
}

.success-icon {
  color: #10b981;
}

.resend-button {
  background: transparent;
  border: none;
  color: #666;
  font-size: 14px;
  cursor: pointer;
  text-align: center;
  width: 100%;
}

.resend-button span {
  color: #9333ea;
  text-decoration: underline;
}

.error-message {
  color: #ef4444;
  font-size: 14px;
  text-align: center;
  margin-top: -8px;
  margin-bottom: 16px;
}

.powered-by {
  text-align: center;
  font-size: 12px;
  color: #999;
  padding-top: 16px;
  border-top: 1px solid #f3f4f6;
}

.powered-by strong {
  color: #666;
  font-weight: 600;
}
</style>