<!-- includes/loader.php -->
<style>
  #loader {
    position: fixed;
    width: 100%;
    height: 100%;
    background: white;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 9999;
  }

  #loader img {
    width: 100px;
    margin-bottom: 20px;
  }

  .spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #ccc;
    border-top: 4px solid #007bff;
    border-radius: 50%;
    animation: spin 1s linear infinite;
  }

  .loading-text {
    margin-top: 15px;
    font-family: Arial, sans-serif;
    color: #333;
  }

  @keyframes spin {
    to { transform: rotate(360deg); }
  }

  body.loaded #loader {
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.5s ease;
  }
</style>

<div id="loader">
  <img src="PSPF logo.png" alt="Logo" />
  <div class="spinner"></div>
  <div class="loading-text">Loading, please wait...</div>
</div>

<script>
  window.addEventListener("load", function () {
    document.body.classList.add("loaded");
  });
</script>
