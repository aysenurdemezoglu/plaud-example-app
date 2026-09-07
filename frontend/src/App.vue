<script setup>
import { computed, onMounted, ref } from 'vue'

const email = ref('')
const password = ref('')
const connected = ref(false)
const recordings = ref([])
const selectedRecording = ref(null)
const loading = ref(true)
const submitting = ref(false)
const error = ref('')
const copiedSection = ref('')
const searchQuery = ref('')
const sortOrder = ref('newest')

const isDetail = computed(() => selectedRecording.value !== null)
const visibleRecordings = computed(() => {
  const query = searchQuery.value.trim().toLocaleLowerCase()
  const filtered = recordings.value.filter((recording) => recording.filename.toLocaleLowerCase().includes(query))

  return [...filtered].sort((first, second) => {
    if (sortOrder.value === 'duration') return second.durationMinutes - first.durationMinutes

    const firstDate = Date.parse(first.date.replace(' / ', ' ')) || 0
    const secondDate = Date.parse(second.date.replace(' / ', ' ')) || 0
    return sortOrder.value === 'oldest' ? firstDate - secondDate : secondDate - firstDate
  })
})

async function request(path, options = {}) {
  const response = await fetch(path, {
    credentials: 'same-origin',
    ...options,
  })
  const data = await response.json()
  if (!response.ok) {
    throw new Error(data.message || 'Something went wrong.')
  }
  return data
}

async function loadRecordings() {
  loading.value = true
  error.value = ''
  try {
    const data = await request('/api/recordings')
    recordings.value = data.recordings
    connected.value = true
  } catch (requestError) {
    connected.value = false
    if (requestError.message !== 'Plaud connection required.') {
      error.value = requestError.message
    }
  } finally {
    loading.value = false
  }
}

async function connect() {
  submitting.value = true
  error.value = ''
  try {
    const body = new URLSearchParams({ email: email.value, password: password.value })
    await request('/api/connect', { method: 'POST', body })
    password.value = ''
    await loadRecordings()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    submitting.value = false
  }
}

async function openRecording(id) {
  loading.value = true
  error.value = ''
  try {
    const data = await request(`/api/recordings/${encodeURIComponent(id)}`)
    selectedRecording.value = data.recording
    window.history.pushState({ id }, '', `/?id=${encodeURIComponent(id)}`)
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    loading.value = false
  }
}

function closeDetail() {
  selectedRecording.value = null
  copiedSection.value = ''
  window.history.pushState({}, '', '/')
}

async function copyText(section, text) {
  if (!text || !navigator.clipboard) return
  await navigator.clipboard.writeText(text)
  copiedSection.value = section
  window.setTimeout(() => {
    if (copiedSection.value === section) copiedSection.value = ''
  }, 1600)
}

async function disconnect() {
  await request('/api/logout', { method: 'POST' })
  connected.value = false
  recordings.value = []
  selectedRecording.value = null
  email.value = ''
  error.value = ''
}

function restoreRoute() {
  const id = new URLSearchParams(window.location.search).get('id')
  if (id) openRecording(id)
}

onMounted(async () => {
  window.addEventListener('popstate', () => {
    selectedRecording.value = null
    restoreRoute()
  })
  await loadRecordings()
  restoreRoute()
})
</script>

<template>
  <main class="shell">
    <header class="topbar">
      <a class="brand" href="/" @click.prevent="closeDetail">
        <span class="brand-mark">P</span>
        <span>Plaud Workspace</span>
      </a>
      <span class="connection-status">
        <span class="status-dot" :class="{ 'is-connected': connected }"></span>
        {{ connected ? 'Connected' : 'Not connected' }}
      </span>
    </header>

    <div v-if="error" class="alert" role="alert">{{ error }}</div>

    <section v-if="!connected" class="hero">
      <p class="eyebrow">Plaud integration workspace</p>
      <h1>Your conversations,<br /><em>ready to revisit.</em></h1>
      <p class="hero-copy">Connect a Plaud account to explore recordings, transcripts, and summaries in one focused workspace.</p>

      <form class="connect-panel" @submit.prevent="connect">
        <div>
          <p class="panel-kicker">Temporary connection</p>
          <h2>Connect your Plaud account</h2>
          <p class="panel-copy">Credentials are sent to the PHP session and never exposed to the Vue interface.</p>
        </div>
        <div class="connect-form">
          <label><span>Email address</span><input v-model="email" type="email" placeholder="you@example.com" autocomplete="email" required /></label>
          <label><span>Password</span><input v-model="password" type="password" placeholder="Plaud password" autocomplete="current-password" required /></label>
          <button type="submit" :disabled="submitting">{{ submitting ? 'Connecting...' : 'Connect account' }} <span aria-hidden="true">&rarr;</span></button>
          <small>OAuth will replace this temporary flow after private-beta access.</small>
        </div>
      </form>
    </section>

    <template v-else>
      <section v-if="isDetail && selectedRecording" class="detail-heading">
        <a class="back-link" href="/" @click.prevent="closeDetail">&larr; All recordings</a>
        <p class="eyebrow">Recording detail</p>
        <h1>{{ selectedRecording.filename }}</h1>
        <p class="detail-meta">{{ selectedRecording.date || 'Undated recording' }} &middot; {{ selectedRecording.durationMinutes }} min</p>
        <div class="detail-stats" aria-label="Recording content summary">
          <span><strong>{{ selectedRecording.transcript ? selectedRecording.transcript.length.toLocaleString() : 0 }}</strong> transcript characters</span>
          <span><strong>{{ selectedRecording.summary ? 'Ready' : 'None' }}</strong> summary</span>
        </div>
      </section>

      <section v-if="isDetail && selectedRecording" class="content-grid" aria-label="Recording content">
        <article class="content-panel">
          <div class="section-heading"><div><span class="panel-kicker">Transcript</span><span class="content-state">{{ selectedRecording.transcript ? 'Available' : 'Not available' }}</span></div><button v-if="selectedRecording.transcript" class="text-button" type="button" @click="copyText('transcript', selectedRecording.transcript)">{{ copiedSection === 'transcript' ? 'Copied' : 'Copy' }}</button></div>
          <div class="rich-text">{{ selectedRecording.transcript || 'No transcript is available for this recording.' }}</div>
        </article>
        <article class="content-panel summary-panel">
          <div class="section-heading"><div><span class="panel-kicker">Summary</span><span class="content-state">{{ selectedRecording.summary ? 'Available' : 'Not available' }}</span></div><button v-if="selectedRecording.summary" class="text-button" type="button" @click="copyText('summary', selectedRecording.summary)">{{ copiedSection === 'summary' ? 'Copied' : 'Copy' }}</button></div>
          <div class="rich-text">{{ selectedRecording.summary || 'No summary is available for this recording.' }}</div>
        </article>
      </section>

      <template v-else>
        <section class="workspace-heading">
          <div><p class="eyebrow">Plaud workspace</p><h1>Recordings</h1><p class="hero-copy">A focused view of your recent conversations.</p></div>
          <button class="secondary-button" type="button" @click="disconnect">Disconnect</button>
        </section>
        <section class="recording-tools" aria-label="Recording filters">
          <label class="search-field"><span>Search recordings</span><input v-model="searchQuery" type="search" placeholder="Search by title" /></label>
          <label class="sort-field"><span>Sort by</span><select v-model="sortOrder"><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="duration">Longest first</option></select></label>
          <span class="result-count">{{ visibleRecordings.length }} of {{ recordings.length }} recordings</span>
        </section>
        <section class="recording-list" aria-label="Recordings">
          <div v-if="loading" class="empty-state"><h2>Loading recordings</h2><p>Fetching your Plaud workspace.</p></div>
          <div v-else-if="recordings.length === 0" class="empty-state"><h2>No recordings yet</h2><p>Your active Plaud recordings will appear here.</p></div>
          <div v-else-if="visibleRecordings.length === 0" class="empty-state"><h2>No matching recordings</h2><p>Try a different title or clear the search.</p></div>
          <button v-for="recording in visibleRecordings" v-else :key="recording.id" class="recording-row" type="button" @click="openRecording(recording.id)">
            <span class="recording-index">&#8599;</span><span class="recording-title"><strong>{{ recording.filename }}</strong><small>{{ recording.date || 'Undated recording' }}</small></span><span class="recording-duration">{{ recording.durationMinutes }} min</span><span aria-hidden="true">&rarr;</span>
          </button>
        </section>
      </template>
    </template>
  </main>
</template>
