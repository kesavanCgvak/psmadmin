<style>
    #emailLogActionLoader {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 100000;
        background: rgba(0, 0, 0, 0.45);
        align-items: center;
        justify-content: center;
        padding: 1rem;
        touch-action: none;
    }

    #emailLogActionLoader.is-active {
        display: flex;
    }

    #emailLogActionLoader .email-log-loader-card {
        background: #fff;
        border-radius: 8px;
        padding: 2rem 1.5rem;
        text-align: center;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
        max-width: 420px;
        width: 100%;
    }

    #emailLogActionLoader .spinner-border {
        width: 3rem;
        height: 3rem;
    }

    body.email-log-action-locked {
        overflow: hidden;
    }
</style>

<div id="emailLogActionLoader" aria-live="polite" aria-busy="false" hidden>
    <div class="email-log-loader-card">
        <div class="spinner-border text-primary mb-3" role="status">
            <span class="sr-only">Loading...</span>
        </div>
        <p class="mb-0">Processing your request...</p>
    </div>
</div>
