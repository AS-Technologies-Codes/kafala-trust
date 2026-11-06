<script setup>
import { ref } from 'vue'

const email = ref('')
const submitted = ref(false)

function joinList() {
  if (!email.value) return
  submitted.value = true
  email.value = ''
}
</script>

<template>
  <main class="page-shell">
    <div class="texture" aria-hidden="true"></div>

    <nav class="nav" aria-label="Primary navigation">
      <a class="brand" href="#" aria-label="Kifalah Trust home">
        <span class="brand-mark" aria-hidden="true">
          <svg viewBox="0 0 52 52" role="img">
            <path d="M26 5C20 12 17 18 17 25c0 6 3 11 9 14 6-3 9-8 9-14 0-7-3-13-9-20Z" />
            <path d="M8 19c6 1 11 4 14 9M44 19c-6 1-11 4-14 9M13 37c5-1 9 0 13 5 4-5 8-6 13-5" />
          </svg>
        </span>
        <span class="brand-copy">
          <strong>Kifalah</strong>
          <small>Trust</small>
        </span>
      </a>

      <span class="nav-note"><i></i> A new chapter is taking shape</span>
    </nav>

    <section class="hero">
      <div class="hero-copy">
        <div class="eyebrow"><span>Coming soon</span><b></b></div>
        <h1>Care that reaches<br /><em>where it matters.</em></h1>
        <p class="intro">
          We’re building a thoughtful new digital home for Kifalah Trust—one that makes it easier to connect, contribute, and create lasting impact together.
        </p>

        <form class="notify-form" @submit.prevent="joinList" :class="{ sent: submitted }">
          <template v-if="!submitted">
            <label class="sr-only" for="email">Email address</label>
            <input id="email" v-model="email" type="email" required placeholder="Your email address" autocomplete="email" />
            <button type="submit">
              Keep me informed
              <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7 4 6 6-6 6M13 10H2" /></svg>
            </button>
          </template>
          <p v-else class="success-message" role="status">
            <span>✓</span> Thank you — you’re on the list.
          </p>
        </form>

        <p class="privacy-note">Only meaningful updates. No noise, ever.</p>
      </div>

      <div class="hero-art" aria-hidden="true">
        <div class="orbit orbit-one"></div>
        <div class="orbit orbit-two"></div>
        <div class="sun"></div>
        <div class="arch arch-back"></div>
        <div class="arch arch-front">
          <svg class="leaf leaf-one" viewBox="0 0 140 200">
            <path d="M70 195C70 130 67 68 77 10" />
            <path d="M74 46C49 46 30 31 20 15c27-3 45 7 54 31ZM72 75c28 0 48-15 58-34-29-3-49 9-58 34ZM70 108c-30-2-52-20-62-41 32-2 53 13 62 41ZM70 143c28 0 49-16 60-39-31-1-51 15-60 39Z" />
          </svg>
          <svg class="leaf leaf-two" viewBox="0 0 140 200">
            <path d="M70 195C70 130 67 68 77 10" />
            <path d="M74 46C49 46 30 31 20 15c27-3 45 7 54 31ZM72 75c28 0 48-15 58-34-29-3-49 9-58 34ZM70 108c-30-2-52-20-62-41 32-2 53 13 62 41ZM70 143c28 0 49-16 60-39-31-1-51 15-60 39Z" />
          </svg>
          <div class="horizon"></div>
          <div class="ripple r1"></div>
          <div class="ripple r2"></div>
          <div class="ripple r3"></div>
        </div>
        <div class="impact-card">
          <span class="mini-mark">K</span>
          <p><strong>Built with purpose.</strong><br />Rooted in trust.</p>
        </div>
      </div>
    </section>

    <footer class="footer">
      <p>Compassion <span>•</span> Dignity <span>•</span> Community</p>
      <p>© {{ new Date().getFullYear() }} Kifalah Trust</p>
    </footer>
  </main>
</template>
