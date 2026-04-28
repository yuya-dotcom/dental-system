function goWithLoading(url){
    const loader = document.getElementById("pageLoader");

    if (loader) {
        loader.classList.remove("d-none");
    }

    window.location.href = url;
}