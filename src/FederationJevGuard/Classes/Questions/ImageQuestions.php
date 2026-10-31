<?php

    namespace FederationJevGuard\Classes\Questions;

    use FederationJevGuard\Interfaces\QuestionInterface;
    use FederationJevGuard\Objects\SystemOne\NoulQuestion;

    /**
     * The questions an image is judged with, each finding is the probability of one kind of spam image. The findings of
     * an image are stored by its hash and given to every review of the evidence it's attached to
     */
    class ImageQuestions
    {
        public const string PROMOTIONAL = 'promotional';
        public const string CONTACT_ROUTE = 'contact_route';
        public const string SCAM = 'scam';
        public const string PAID_ADULT = 'paid_adult_offer';
        public const string MALWARE = 'malware_lure';

        /**
         * Returns the questions, the state holds the image as `image` and the text sent with it as `message`
         *
         * @return array<string, QuestionInterface> The questions by name
         */
        public static function build(): array
        {
            return [
                self::PROMOTIONAL => new NoulQuestion(
                    'Is `image` an advertisement or a promotional graphic made to sell or promote something?',
                    ['what' => 'An ad, a flyer, a banner, a price list or a product shot made to sell', 'examples' => ['a casino banner', 'a poster for a paid channel']],
                    ['what' => 'A photo, screenshot, meme or picture shared in a conversation that promotes nothing']
                ),
                self::CONTACT_ROUTE => new NoulQuestion(
                    'Does `image` show a way to contact, follow or join someone, such as a URL, a username, a phone number, an invite link or a QR code?',
                    ['what' => 'Visible contact details or a QR code meant for the viewer'],
                    ['what' => 'No contact details, or only ones that are part of an ordinary screenshot']
                ),
                self::SCAM => new NoulQuestion(
                    'Does `image` look like part of a scam?',
                    ['what' => 'Made to trick the viewer out of money or data', 'examples' => ['fake profit or payout screenshots', 'crypto or investment schemes', 'fake giveaways or prizes', 'fake support or payment notices']],
                    ['what' => 'Nothing that tries to trick the viewer']
                ),
                self::PAID_ADULT => new NoulQuestion(
                    'Does `image` offer sex, sexual shows or adult content for money?',
                    ['what' => 'An escort ad, prices for sexual services, or an ad for paid adult content'],
                    ['what' => 'Nothing is offered for money', 'not_for' => 'A sexual or explicit picture that sells nothing']
                ),
                self::MALWARE => new NoulQuestion(
                    'Does `image` push the viewer to download, install or run something?',
                    ['what' => 'A fake update, a fake download button, a cracked app or a cheat to install'],
                    ['what' => 'Nothing to download or install']
                ),
            ];
        }
    }
