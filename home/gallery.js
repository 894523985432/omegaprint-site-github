$(document).ready(function(){
    $('.slick').slick({
        dots: false,
        centerPadding: '150px',
        speed: 1000,
        slidesToShow: 4,
        slidesToScroll: 1,
        adaptiveHeight: true,
        responsive: [
          {
            breakpoint: 1900,
            settings: {
                slidesToShow: 4,
                slidesToScroll: 1,
                
            }
        },
            {
                breakpoint: 1200,
                settings: {
                    slidesToShow: 3,
                    slidesToScroll: 1,
                    dots: false,
                    adaptiveHeight: true,
                }
            },
        {
            breakpoint: 966,
            settings: {
                slidesToShow: 2,
                slidesToScroll: 1,
                dots: false,
                adaptiveHeight: true,
            }
        },
        {
          breakpoint: 768,
          settings: {
                slidesToShow: 1,
                slidesToScroll: 1,
                dots: false,
                adaptiveHeight: true,
          }
    }

  ]
      });

      $('.slider').slick({
        slidesToShow: 1,
        slidesToScroll: 1,
        arrows: true,
        fade: true,
        asNavFor: '.slider-nav'
      });
      $('.slider-nav').slick({
        slidesToShow: 2,
        slidesToScroll: 1,
        asNavFor: '.slider',
        centerMode: true,
        focusOnSelect: true,
        variableWidth: false,
        vertical: true,
        responsive: [
          {
              breakpoint: 1200,
              settings: {
                slidesToShow: 2,
                slidesToScroll: 1,
                vertical: false,
                centerMode: true,
                variableWidth: true,
                focusOnSelect: true,
              }
          },
    ]
      });



      $('.with-slick.gallary_main').slick({
        dots: true,
        infinite: true,
        speed: 800,
        autoplay: true,
        autoplaySpeed:4000,
        slidesToShow: 1,
        adaptiveHeight: true,
      });
      $('.with-slick.gallary_discount').slick({
        dots: true,
        infinite: true,
        speed: 800,
        arrows:true,
        autoplay: true,
        autoplaySpeed:4000,
        slidesToShow: 1,
        adaptiveHeight: true,
      });
          
});