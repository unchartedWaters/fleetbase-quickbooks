import Service from '@ember/service';

export default class ModalsManagerStub extends Service {
    last = null;
    decline = false;

    confirm(options) {
        this.last = options;
        if (this.decline) {
            return options.decline?.();
        }

        return options.confirm?.({
            startLoading() {},
            stopLoading() {},
            done() {},
        });
    }
}
