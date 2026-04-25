function goWithLoading(url){

    const loader = document.getElementById("pageLoader");

    loader.classList.remove("d-none");

    setTimeout(function(){
        window.location.href = url;
    },1000);   // 1 second loading

}

